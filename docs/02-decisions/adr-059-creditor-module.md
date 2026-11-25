# ADR-059 — Creditor module: record model, posting strategy, payment date

**Status:** `[APPROVED]` — owner answers §11 of the creditor plan, 2026-11-15; stage 1a (creditor),
1b (invoice, Kreditorenjournal) and 1c (payment date, posting run) built 2026-11-15.
**Date:** 2026-11-15
**Build plan:** [`creditor-module-bauplan.md`](../03-development/creditor-module-bauplan.md) (§2, §6,
§7, §11). **Topic:** [`creditor.md`](../topics/creditor.md).

---

## Context

Supplier invoices arrive as PDF. Until module-creditor they were booked by hand in the journal or
captured as receipts (module-receipt), without a supplier record, without duplicate detection and
without the invoice's own data (number, QR reference, due date). Swiss SMEs book creditors either
**directly** (the expense on payment — cash principle) or **over a collective account** (the
invoice as a liability on 2000, the payment against it). z77.ch is not liable to VAT and books on
the cash principle; a later installation may need the collective variant.

Questions: what is a creditor (own entity or a contact)? How does an invoice reach the ledger, and
when? How is the variant switched without re-interpreting old invoices? Which modules may the new
one depend on?

## Decision

1. **A creditor is a CONTACT with a creditor profile** (the debtor model): `creditor_profile`
   (UID, posting defaults) + `creditor_iban` (several, a shared IBAN allowed), switched on by the
   contact role «Kreditor» (module-contact's `contactRoles`). No creditor number.
2. **One invoice = one record with LINES** (owner answer 2): `creditor_invoice` + `creditor_invoice_line`;
   a split is lines inside one invoice, not two records — one identity, one later open item, one
   entry. The PDF lies unaltered in the DMS; the SHA-256 of the file is read once (`creditor_intake`).
3. **Posting behind a strategy** — `Posting\CreditorPostingStrategy` (`key()`, `invoicePosting()`,
   `paymentPosting()`). Only **`DirectPosting`** is built: nothing on arrival; on payment ONE
   generated entry per invoice — per line its gross on the line's account (net + input VAT through
   financial's `GrossEntryBuilder`), against the payment method's credit account — source
   `creditor`, key `creditor:{id}`. *(At most a second, tax entry per invoice, the reverse-charge
   one also before the payment — addendum 2026-11-25.)*
4. **The variant is chosen per INSTALLATION** (`creditorConfig['postingVariant']`, read only in
   `CreditorPostingVariant::current()`; a project overrides it under `override/`) and **snapshotted
   on every invoice** (`posting_variant`). The run resolves the strategy from the invoice
   (`strategyFor()`), never from the config. A value that is not built is refused loudly.
5. **The posting date is the PAYMENT date** «bezahlt am» (owner decision 2). «Bezahlt am …» is set
   per invoice in its window or for ticked rows at once (`id:version` each, optionally «Bezahlt
   mit»); never in the future.
6. **Posting is a RUN** «Journal verbuchen …» up to a cut-off on the payment date (owner answer 3,
   the Belegjournal's window): every invoice still in the journal paid on or before it; unpaid
   invoices are no part of it and block nothing *(except for the tax entry of an unpaid invoice
   with reverse charge — addendum 2026-11-25)*; a blocked (suspected duplicate) or incomplete one
   in the run blocks the whole run. All or nothing, one protocol row (`creditor_journal_posting`),
   the invoices `posted` and immutable afterwards.
7. **VAT liability is applied strictly** (owner answer 5): a mandator not liable to VAT forces
   «ohne MWST» on every line at saving, and the builder refuses a tax code at posting
   (`GrossEntryBuilder::build($liableToVat: false)`).
8. **Retention on posting** (owner answer 8): the posted invoice's PDF gets `retentionUntil` = end
   of the fiscal year of the payment date + 10 years (OR 958f, MWSTG Art. 70), after the commit;
   «Verbuchte Journale» backfills.
9. **Delete** (owner 2026-11-15): an UNPOSTED invoice is deleted completely — intake row included,
   the file hash is free, the PDF goes to the DMS trash; a suspected duplicate DISCARDED keeps its
   intake row (`discarded`), the file stays refused; a posted invoice cannot be deleted.
10. **Module graph:** creditor REQUIRES contact, vat, mandator, financial, dms and **receipt**
    (owner answer 1: payment methods «Bezahlt mit», the field rules `ReceiptFields`, later the FX
    rate). None of them names creditor: financial asks the open-work registry
    (`UnpostedCreditorInvoicesCheck`), receipt asks the `paymentMethodUsage` registry for the usage
    count of a payment method (RECEIPT-PM-COUNT-001).

## Reasoning

- A contact already carries name, address, language and e-mail; a supplier who is also a customer
  stays one party (Rule 2). The role registry exists for exactly this case.
- The direct variant is what the owner books today (cash principle, not liable to VAT); the
  strategy and the per-invoice snapshot keep the collective variant a later addition instead of a
  migration — the invoice already carries invoice date, due date, IBAN and reference.
- A run with a cut-off is the pattern the owner already uses for receipts and debtor invoices:
  one confirmation, one protocol, all or nothing; posting per row would scatter the books.
- The payment date as posting date makes the bank statement and the ledger agree line by line.

## Consequences

- **Cash principle, said in the help:** an invoice of December paid in January is an expense of the
  new year; at the year end unpaid invoices are not in the books as liabilities (the period close
  WARNS with their count and total). The collective variant is the proper accrual path.
- **VAT of a liable installation** is claimed at payment, not at receipt — a later deduction,
  legally fine on «vereinbarte Entgelte», but the quarter's VAT return differs from the invoices
  received in it. The collective variant fixes that.
- **Not built yet:** `CollectivePosting` (2000, the payables reconciliation, the mandator key
  `payable`, the switch only at a fiscal-year start with no open invoice); foreign currency
  (receipt's FX plan); recognition and the folder job (stages 2/3).
- The journal-run pattern now exists three times (debtor, receipt, creditor) — an extraction
  candidate in `financial.md`, consciously deferred.

## Rejected Alternatives

| Option | Why rejected |
|---|---|
| Own creditor entity with address | duplicates the contact; a supplier who is also a customer becomes two parties |
| Split = two invoice records (receipt's E12) | breaks duplicate detection and the later open item / payment |
| Variant per creditor | two accounting methods in one company; 2000 reconciliation and year close unreadable |
| Read the variant from config at posting | a later switch would re-interpret invoices received under the old method |
| «Buchen» per selected row | scatters the books; no single confirmation and protocol (owner answer 3) |
| Posting date = invoice date in the direct variant | the expense would precede the payment; bank and ledger would not agree |

## Addendum 2026-11-25 — a second entry per invoice (owner decisions)

Decided in the owner's round of 2026-11-25 on the VAT return's stages S5 / S6; the VAT side is
[ADR-041](adr-041-vat-model.md) addendum 2026-11-25, points 7 to 10. A clause marked *derived*
is not an owner decision but a rule that follows from the decisions.

1. **At most TWO generated entries per invoice** — *changes decisions 3 and 6* («ONE generated
   entry per invoice»): the expense entry (key `creditor:{id}`, the payment date) and a tax entry
   (key `creditor:{id}:vat`): the input tax of an invoice paid in a settled period, caught up
   within the calendar year (ADR-041 addendum, point 9), or the reverse-charge pair on the invoice
   date (point 10). When invoice and payment date are equal and nothing is caught up, the invoice
   keeps ONE entry. *Derived:* the invoice references its tax entry; when a catch-up and a
   reverse-charge pair would fall on different dates, the run refuses that invoice with a sentence
   (capture it as two invoices) — by hand rather than a third entry.
2. **The reverse-charge entry is posted BEFORE the payment** — *changes decisions 3 and 6*
   («nothing on arrival», «unpaid invoices are no part of the run»): the run «Journal verbuchen»
   also takes every UNPAID invoice with a reverse-charge line whose invoice date lies on or before
   its cut-off, for its tax entry only; the invoice stays in the journal, unpaid and editable. The
   expense follows at the payment, without a code. When the invoice date lies in a VAT period
   already posted, the run posts no tax entry: the reverse charge goes the correction path
   (ADR-041 addendum, point 10). Reason: the tax debt arises at receipt of the invoice (Art. 48
   para. 1 lit. b MWSTG, as the decision notes give it); no tolerance for declaring it at payment
   was found.
3. *Derived:* **while the tax entry exists and the invoice is not posted,** a save that changes its
   tax facts (reverse-charge lines, invoice date, «ESTV-Kurs») re-posts the tax entry in place, a
   delete or the removal of the reverse-charge lines retracts it — the source corrects its own
   entry (ADR-042 addendum 2026-10-06) — while its VAT period is not settled; afterwards the
   ledger's freeze refuses and the correction is by hand. *Decision 6's «posted and immutable»
   holds for the posted invoice, unchanged.* The VAT return has a BLOCKING open point while an
   invoice with a reverse-charge line dated in its period has no tax entry.
4. **The input-tax rate follows the invoice date** (empty: the payment date); the posting date stays
   the payment date — decision 5 unchanged (ADR-041 addendum, point 7).

**Amendment 2026-11-25 b (owner rulings later the same day — ADR-042 addendum 3, ADR-041 addendum
2026-11-25 b):**

- *Point 2, last sentences, made precise* (O-2, O-3, O-8): the period of the reverse charge is the
  period of the INVOICE date. When it is already posted, «Journal verbuchen» does not silently skip
  the tax entry: it REFUSES the invoice with «Die Bezugsteuer gehört in die Periode {Periode}, sie
  ist abgerechnet — die Rechnung «ohne MWST» verbuchen und die Bezugsteuer als Korrekturbuchung
  zu {Periode} erfassen.»; the user switches the line to «ohne MWST», posts, and enters the reverse
  charge by hand as a correction entry naming that period. Also within the same calendar year —
  nothing is caught up in the running period. z77 does not check that the correction entry was made.
- *Point 3, last sentence, made precise* (O-8): the BLOCKING open point of the VAT return looks
  ONLY at the period being posted — it blocks when an invoice carries a reverse-charge code, its
  INVOICE date lies in that period and its tax entry is missing (the way out: «Journal verbuchen»
  posts the tax). It never blocks because of an invoice whose period of origin is already posted
  (that one is refused by the run, above). No deadlock.
- *Point 1* — the input-tax catch-up of the second entry is decided by the PAYMENT date (O-1), the
  reverse-charge pair by the INVOICE date (O-2); two separate rules in two places, never one shared
  date check (O-4).
- **State after wave 1 of the VAT return (2026-11-25):** built in module-creditor — the
  foreign-voucher warning `Close\ForeignWithoutVatCheck` (scope `vat-return`); the account pickers
  without the mandator's VAT accounts and their refusal in `CreditorProfileValidator`. Built in module-financial for this ADR's
  later use — the rule of the input-tax catch-up (`VatSettlement::inputTaxDeferral()`) and the two
  entries (`GrossEntryBuilder::buildSplit()`). **Decided, not built:** points 1 to 4 on the
  creditor side (the second entry, `creditor:{id}:vat`, the tax entry before payment, its amend /
  retract, the blocking open point, the rate of the invoice date), the ESTV-Kurs, the tax-only
  line, the import-VAT warning.
