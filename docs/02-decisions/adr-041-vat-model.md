# ADR-041 — VAT model: tax codes with dated rates, computed once, carried

**Status:** `[APPROVED]` — approved by the owner 2026-09-21 (P0 of [`order-debtor-financial-bauplan.md`](../03-development/order-debtor-financial-bauplan.md)); addendum to decision 7 approved by the owner 2026-09-22; addendum «a mandator not liable to VAT» — owner decision 2026-10-21
**Date:** 2026-09-21 · addendum 2026-09-22 (rate snapshot on the journal line) · addendum 2026-10-21 (a mandator not liable to VAT) · addendum 2026-10-22 (the no-VAT note is part of the snapshot) · addendum 2026-10-22 (the line code is part of the line snapshot; its print 2026-11-12)

---

## Context

In wdv-6.2.2 VAT is a set of float rates in code and configuration. Tax is computed per line in
floats, recomputed where a document is shown or posted, and a rounding difference is smeared onto the
last rate. A rate change (CH 2018, 2024) is a code change, and old documents recompute against the new
rate unless someone remembers otherwise. The findings are in
[`order-financial-review-2026-09-18.md`](../03-development/order-financial-review-2026-09-18.md).

The owner decided (plan §1, D4/D5): an own `module-vat`; rates are data managed in the backend with a
«valid from»; the effective and the agreed method are built, net tax rate and received consideration
are not built but must fit the model unchanged; discount and bad-debt loss correct VAT from day one;
the European model from day one, with only the country pack `CH` built.

## Decision

1. **`module-vat`, file-based.** A few dozen rows, managed in the backend, seeded once on first install
   with the Swiss codes and rates since 2018 (ADR-024), so historical documents resolve.
2. **Tax code and rate are separate.** A `TaxCode` (`code`, `country`, `category`, `label`, `active`)
   says *what kind* of tax; a `TaxRate` (`code`, `validFrom`, `rate`) says *how much from when*. A rate
   change is a **new row**; old rows stay. Categories: standard, reduced, special, zero, exempt,
   reverse-charge, input-material, input-other.
3. **Rates are integers in hundredths of a percent** (8.1 % = `810`); amounts are `Money` (integer
   minor units, ADR-042). No float anywhere in the calculation.
4. **The rate is resolved by the service date** — legally correct, and what wdv already does.
5. **`VatCalculator` computes once per document.** Input: lines (net or gross amount, tax code),
   service date, price mode. Output: the resolved rate per line and a **tax summary per code**
   (base, rate, tax).
   - Tax is computed on the **sum per code**, rounded half away from zero to 0.01 (commercial
     rounding; a negative tie −0.005 → −0.01, so a credit note mirrors its invoice — clarified
     2026-09-21, owner; the earlier wording said «half-up») — not per line and summed.
   - Gross mode: tax = gross × rate / (10000 + rate).
   - Rounding the document total to 0.05 is **not** a VAT concern; debtor adds it as a separate
     rounding line (plan §6.2).
6. **Computed once, then carried.** The invoice stores its lines with rate and the tax summary as a
   snapshot. Nothing downstream — PDF, posting, VAT return, discount, loss — recomputes VAT from the
   code table; each reads the snapshot. A later rate change or a corrected tax code can never alter an
   issued document or its postings.
7. **Net posting method.** Every journal line with VAT carries `taxCode`, `taxBase` and `taxAmount`;
   revenue and expense are posted net, the tax to its own account per code. The VAT return is the sum
   of these lines per code and rate (plan §5.6) — it reads the ledger, not the invoices.
8. **Discount and loss correct VAT proportionally** per code of the invoice's tax summary
   (`Money::allocate`, no lost Rappen), posted with the allocation (plan §6.3).
9. **Country packs map codes to a tax authority's form.** Pack `CH` maps code (+ rate) to the ESTV form
   fields and is used only by financial's VAT return. Another country is a new pack, not a model
   change.
10. **Methods built: effective and agreed.** Net tax rate (Saldosteuersatz) and received consideration
    (vereinnahmt) are not built; the model carries them without change — a net-tax-rate installation
    is a different pack and different settlement posting on the same lines, received consideration a
    different selection date on the same lines.

## Reasoning

- **Rates as dated data** make a rate change a backend entry on the day, not a release — and keep every
  earlier document resolvable against the rate that applied then.
- **Sum per code, then round** is what the ESTV form adds up; rounding per line and summing produces
  the Rappen differences wdv smears onto the last rate.
- **Computing once** is the only way an issued document stays immutable in substance, not just in
  its PDF. Recomputing at posting or return time would let a table edit reach into filed periods.
- **Net posting with tax data on the line** makes the VAT return a query on the ledger. The same lines
  answer the effective return today and a net-tax-rate or received-consideration return later.
- **A separate module** keeps VAT out of financial's and debtor's code: financial sums what it is
  given, debtor asks the calculator, order uses it for display only.

## Consequences

- VAT is computed in exactly one class. order shows VAT on quotes and orders by calling it, for
  display only.
- Documents and journal lines reference a tax code by `code`, snapshotted at use; a code is deactivated,
  never deleted (the reference rule for file-based master data: ADR-043, decision 19).
- The wdv float bugs — strict float compares, unrounded VAT, rounding smeared onto the last rate —
  become test cases (plan §3).
- A rounding difference between the VAT return and the ledger is posted to the VAT-return rounding
  account and shown, never absorbed (plan §5.6). It is a different account from the invoice-rounding
  account of the 0.05 line.
- The migration imports wdv's journal lines with their tax data as booked (P5b); the per-quarter VAT
  returns must match wdv's (acceptance, plan §8).

## Rejected Alternatives

| Option | Why rejected |
|---|---|
| Rates in code or config (wdv) | A rate change becomes a release; old documents recompute against the new rate |
| One row per code with the rate on it | A rate change overwrites history |
| Tax per line, rounded, then summed | Rappen differences against the form; what wdv smears onto the last rate |
| Recompute VAT at posting or return time | A table edit could reach into issued documents and filed periods |
| Gross posting with period-end separation | The VAT return could no longer be read line by line from the ledger |
| Build net tax rate and received consideration now | No installation uses them today; the model carries them, building them is waste until one does |
| Rate resolution by invoice date | Legally wrong for a service rendered before a rate change |

## Addendum 2026-09-22 — the journal line carries the rate (approved by the owner)

Decision 7 names `taxCode`, `taxBase` and `taxAmount` on a journal line. The line carries a
**`taxRate` snapshot** as well (hundredths of a percent, like decision 3): the VAT return groups by
code **and rate** (plan §5.6), and around a rate change (CH 2023/2024) two rates of one code meet in
one period — code, base and amount alone could not tell them apart, and resolving the rate again from
the table at return time is exactly the recomputation decision 6 rules out. An addition to decision 7,
not a contradiction: the rate comes from the document's snapshot like the other three fields.

The **label is not snapshotted on a journal line**: label snapshots belong to documents (an invoice
prints the label as it read then), a ledger line reports by code + rate. Consistent with the
reference rule in [`vat.md`](../topics/vat.md) for a Doctrine reference to a tax code. Built in
`module-financial` P2 part 2 (`journal_line.tax_rate`, [`financial.md`](../topics/financial.md)).

## Addendum 2026-10-21 — a mandator not liable to VAT (approved by the owner)

The mandator's flag «mehrwertsteuerpflichtig» (`mandator.liable_to_vat`, until now without effect) is
BINDING for documents: a company not liable to VAT issues its invoices, credit notes, quotes and order
confirmations WITHOUT VAT. Decisions 5–7 apply only to a document computed WITH VAT; for one without:

- **No calculation**: `VatCalculator` is not called, there is no tax summary, net = the lines, and the
  price mode changes no amount. The rule lives in ONE place, debtor's `InvoicingService::chargesVat()`
  (module-order reads it through debtor's preview, decision «order uses it for display only» unchanged).
- **No tax code on the document lines** (NULL, the shape a dunning fee already has) — not the article's
  code at rate 0: a coded line would be reported in the VAT return (decision 7 reads the journal line's
  tax data) and would snapshot a label and a rate for a tax that does not exist. The order keeps its
  article's code on its own line; the document drops it.
- **No VAT on the posting**: receivable against revenue (and rounding); no VAT account.
- **A credit note follows its invoice**, not the flag: a reduction of consideration is treated as the
  supply was (decision 4's reasoning) — with VAT exactly when the invoice carries it.
- **Decision 6 holds**: the flag is read when a document is COMPOSED; an issued document keeps its
  snapshot, and the print decides its VAT presentation from the snapshot (`Invoice::carriesVat()`), not
  from today's flag.
- No mandator record (or one that cannot be read) counts as liable — the behaviour before the addendum.
  *Note 2026-11-20 (owner-approved):* this answer — liable or not, with that default — is read through ONE
  method, module-mandator's `CurrentMandator::liableToVat()`; `chargesVat()` keeps the document rule (fee,
  credit note) and asks it. module-article's validator reads the same answer: while NOT liable an article's
  tax code is optional (a document of a non-liable company carries no code anyway); a code that is given is
  still judged.

The «no silent 0 %» rule of `vat.md` is untouched: not being liable is a declared state of the company,
not a data gap; a document computed with VAT still refuses an unknown code or a missing rate.

**Addendum 2026-10-22 — the note is part of the snapshot (owner decision):** a document composed
WITHOUT VAT says so — the mandator's «Hinweis ohne MWST» (`mandator.vat_exempt_note`, read as
`Mandator::documentVatNote()`: the text only while not liable) is stored on the document at composing
(`invoice.vat_note`) and printed under the total from THERE — decision 6 applied to the sentence as to
the amounts: a reprint reads as issued, a later text change or a switch turned on does not reach a
booked document. A credit note of a no-VAT invoice takes the text as it reads when IT is composed; a fee
(no VAT by nature) carries the note too while the company is not liable. A quote or order confirmation
(module-order) is a transient preview and prints the mandator's current text. Never printed on a
document computed with VAT.

**Addendum 2026-10-22 — the line code is part of the line snapshot (owner decision):** a document line
may carry a short code (`invoice_line.code`, the wdv `shop_positions.code`). It is stored with the line at
composing and read from THERE — decision 6 applied to one more line field: a re-issue replaces it with the
lines, a booked document keeps it, a credit note copies it from its invoice's lines. Not a VAT concern; the
framework's layouts do not print it (a project's override decides — `debtor.md` «The line code»).
*Superseded in its last sentence on 2026-11-12 (owner decision):* the framework's layouts print the code
small above the line's text (`pdf/table` `eyebrow`, `pdf.md`); a line without a code takes no space. The
snapshot rule is unchanged.

## Addendum 2026-11-24 — the VAT return: method, period, form mapping, reverse charge, import VAT (owner decisions)

Decided before the VAT return (financial's P5 part 2) is built, and corrected the same day after
three reviews — against the VAT law at its primary sources, the daily handling, the build
effort, and by calculating concrete cases
([`vat-return-review-2026-11-24.md`](../03-development/vat-return-review-2026-11-24.md): the
sources, the worked cases, the build plan, and for whom the first version is NOT suitable). The
ledger side — the settlement entry, the freeze, the correction path, lifting a return, the year
close — is the addendum of the same day in [ADR-042](adr-042-ledger-and-money.md).

1. **The method is a field on the mandator record** — both axes: the calculation (effective / net
   tax rate / flat tax rate — three methods in law) × the consideration (agreed / received). Only effective + agreed is built
   (decision 10 unchanged); a mandator set to anything else gets a REFUSED return («nicht
   unterstützt»), never an effective / agreed return under another name. There is no silent
   default: a method not set refuses too. Every return snapshots its method and prints it.
   *Corrects the last half-sentence of decision 10:* received consideration is NOT «a different
   selection date on the same lines» — an invoice is posted at its invoice date and a payment
   carries no tax data, so that method needs an allocation of payments to tax summaries. It stays
   unbuilt.
2. **The settlement rhythm is a field on the mandator record: quarter | month | year** — the three
   the law knows for the effective method (Art. 35 MWSTG; the semester belongs to the net tax
   rate method and is not offered). A change to or from the yearly rhythm takes effect on a
   1 January (Art. 35a MWSTG), a change between quarter and month at a quarter start. The yearly
   rhythm may begin with the liability itself — a short first period there too.
   Returns follow one another without a gap and in order; each return snapshots its from / to. The
   instalments of the yearly settlement are bank postings onto the settlement account, never part
   of a return. The help names the conditions the authority sets for month and year.
3. **«Abrechnung ab» and «Abrechnung bis» bound the liability in z77.** «Abrechnung ab» is the
   start of a settlement period — or the day the liability began, the first period then being
   short. Nothing before it is recomputed — those periods count as settled in the system the
   installation came from, and a system change falls on the end of a period (otherwise that period
   is re-posted in z77).
   - Before «Abrechnung ab» the VAT accounts are free and a posting made in z77 with a tax code is
     refused; from that date on it is the other way round (ADR-042 addendum, point 7). Journal
     lines a migration imports keep their tax data as history (Consequences, unchanged) — the
     return never reads before the date.
   - A setup step shows the balances of the VAT accounts on the day before «Abrechnung ab» and the
     manual entries on them since, and offers the one-off entry, dated that day before, that
     carries the old balance onto the settlement account. From then on the VAT accounts stand at
     zero after every settlement — a check of its own.
   - Two cases cross the date. Founding costs of up to six months before the registration are
     deductible in the first return: claimed as a «tax only» line dated in the first period. And
     an input code on a voucher for a service rendered BEFORE «Abrechnung ab» is not deductible:
     a warning at «MWST buchen».
   - «Abrechnung ab» may lie before today (a registration made retroactive): revenue posted
     since then without a tax code is listed at setup and declared by coded manual entries.
   - «Abrechnung bis» is the end of the liability: the screen stays while a period up to that date
     is not settled.
   - *This replaces the acceptance named in the Consequences* (the per-quarter VAT returns must
     match those of wdv): the acceptance is now «the first return in z77 matches the ledger».
4. **A mandator that is not liable has no VAT return:** no menu entry, no screen (but see
   «Abrechnung bis»); one line in the help says why. The setup checks ask for the VAT settings of
   a liable mandator only.
5. **The form mapping is a readable rule file, keyed by category + rate** — not a PHP class and not
   keyed by code. *This amends decision 9* («maps code (+ rate)»): the categories are what the
   packs agree on (`TaxCategory`), so a code an installation adds lands in the right field without
   touching the pack. The file is data a VAT auditor can read, versioned by the validity of the
   form (the rates before 2024 keep their own fields).
   - The OUTPUT categories and reverse charge map per rate; the INPUT categories map at any rate
     (a purchase at the reduced rate is input tax like any other).
   - One code may carry an exception: export (field 220) and **supplies abroad (field 221), which
     get a seed code of their own** — field 221 is a deduction from field 200 and cannot be added
     in the portal by hand without breaking the form. The code is for supplies abroad that
     would be TAXABLE in Switzerland; an exempt-type supply abroad takes the exempt code.
   - A category + rate the file does not map REFUSES the return — never a silent omission.
   - A return snapshots the mapping it was computed with: a code whose category is changed later
     cannot alter a posted return (decision 6, «computed once»).
   The key stays the tax code of the journal line, not the account: one revenue account holds
   several rates and exports, the account alone does not name the field.
6. **Every figure is traceable to its entries, and the accounts are reconciled — per return and
   per year.** Each form field opens to the accounts and journal entries behind it, with the share
   by origin (invoices, receipts, creditor invoices, bank, manual journal entries — the source type
   is on every entry). The reconciliation compares MOVEMENTS without the settlement entries, not
   balances: field 200 less field 235 against the movements of the revenue accounts, the input
   fields against the input-tax accounts. It names the expected differences — lines on a revenue
   account without a tax code, per account (invoice rounding, default interest); coded output
   lines on accounts of another type; correction entries of the period — and marks only the
   unexplained remainder. Beside the view per return stands the view per year, by rate: the
   turnover and input-tax reconciliation of Art. 128 MWSTV, for the calendar year and, where it
   differs, the business year — the books by rate against the figures DECLARED on the filed
   returns, corrections included; the difference is what is still to declare. The accounts are the CONTROL of the return, not its source.
7. **How the form is computed.**
   - Field 200 is the turnover excluding VAT BEFORE reductions.
   - Field 235 takes the reductions of the TAXABLE categories, picked **by origin** — credit
     notes, discounts and losses — not by sign. A reversal entry with its original and a
     correction entry are netted into field 200 per code and rate: a cancelled invoice is no
     turnover and no reduction. Reductions on zero-rated and exempt codes are netted into field
     200 and into their own field: otherwise the taxable total would not equal the sum of the
     rate fields.
   - The tax of a rate field and of an acquisition-tax field is **base × rate**, kept exact; the
     total owed is the sum of the exact products and the payable amount is rounded ONCE
     (*this makes decision 7 precise:* the return sums the BASES per code and rate). The input
     fields are the tax as POSTED — a voucher's tax differs from base × rate by a Rappen, and a
     «tax only» line has no base. The difference between the declared and the posted tax is what
     the rounding account of point 13 takes.
   - A negative field is shown with a warning, not refused — a large credit note for an earlier
     period produces one in normal work.
   - The checks of the electronic return (eCH-0217: taxable total = sum of the rate fields to the
     Rappen; the payable amount) hold from the first version on, so the later upload file passes.
8. **Reverse charge (Bezugsteuer)** — full deduction assumed.
   - A reverse-charge code is debit-natural like an input code: the coded expense line carries a
     positive base and tax and maps to TWO tax lines — credit the VAT owed, debit the input
     account its code names. Two seed codes say which input field (material and services / other
     expenses); the rule file knows the acquisition-tax field per rate.
   - The two tax lines are dated at the INVOICE date (the debt arises at receipt of the invoice,
     Art. 48 MWSTG) — also in the creditor direct variant, whose expense follows the payment.
   - The base is the invoice amount without foreign VAT, converted at the rate the authority
     publishes — not the amount debited with bank fees.
   - The reverse-charge share of the input field equals the tax DECLARED in the acquisition-tax
     field, so the payable effect is zero to the Rappen.
   - Partial deduction is not built. Built as a later stage; until then a return that holds
     reverse-charge lines REFUSES.
9. **A «tax only» line** on receipts and creditor invoices, with an input code — it extends «the
   tax data sits on the NET line» of decision 7 by exactly this one kind. Two uses: **import VAT**
   (a customs voucher has no net amount; the line is dated by the customs assessment, which is
   also its evidence, not by the forwarder's invoice), and **«Vorsteuer nachholen»** — a supplier
   invoice paid in a settled period posts its expense at the payment date without VAT and its
   input tax in the running period (lawful until the right to deduct lapses, five years after the
   tax period). The deferral procedure for import tax (Verlagerungsverfahren) is not supported.
10. **Input-tax correction, reduction and subsequent deduction** — three categories of their own,
    mapped to their form fields: own use and mixed use; subsidies; and the subsequent input-tax
    deduction (Einlageentsteuerung) of a business that becomes liable with assets on hand. z77 computes nothing here: the bookkeeper
    posts the amount by a manual entry with the code, the ledger carries the figure. *Decided on
    the second review:* without them a sole proprietor's private share — which is not a supply to
    invoice — has no lawful place in the return. A private share of staff or of the owner of a
    company IS a taxable supply and is invoiced with VAT.
11. **A dunning fee of a liable mandator is consideration and carries VAT** — the tax code of the
    dunned invoice (with mixed rates: of its largest share), posted with tax data. Statutory
    default interest and collection costs stay without a code. *This reverses the reasoning
    recorded with DEBTOR-FEE-ACCOUNT-001* («damages, no VAT»): the authority counts dunning fees
    to the consideration of the dunned supply (MWST-Info 04, 3.8.2.3). A fix of its own, ahead of
    the return.
12. **Creditor, direct variant:** input tax falls into the period of the PAYMENT (ADR-059
    decisions 3 and 5) — lawful without approval; the help says so and names the collective
    variant for whoever wants it at receipt of the invoice.
13. **The VAT-return rounding account** of the Consequences is `6945 Rundungsdifferenzen MWST` in
    the KMU chart, named on the mandator record like the other VAT accounts. An existing
    installation creates and names it; the setup check reports it while it is missing. A
    difference above half a Rappen per coded document is a finding.
14. **Output: the screen and a PDF in form-field order.** The PDF prints per field what is typed
    into the portal, the tax as the authority computes it beside the tax as posted, the difference,
    that the figures are declared NET (the portal asks gross or net first) — and that it is not a
    filing. On posting, the PDF with the breakdown per field and account is archived with the
    settlement entry: the compilation for a return is an accounting voucher. The upload file (eCH-0217) is a later step of its own.

15. **Fees of a bank or payment provider are not a reduction of consideration.** A payment that
    arrives net of a commission is cleared by an allocation kind of its own, posted to an expense
    account without a tax code — never as discount or loss, which reduce turnover and VAT
    (MWST-Info 07, 1.1: the consideration is booked before such costs). A fix of its own in
    debtor, ahead of the return.

**Not built, by decision — README and help say each, with the list of who the first version is
not suitable for** (review, section 6.4): net tax rate, received consideration, the upload file,
partial deduction, the option for exempt supplies (its field is information only), the
notification procedure, the flat tax rate method, margin taxation, foreign currency,
prepayments without a document, value vouchers. A field that changes the payable amount is never
added in the portal by hand without a booking. No figure of a return is ever typed into z77: what
the ledger does not carry is not in the return. z77 states its supported scope and never «conforms
to the law» — the return is an aid to self-assessment, the taxable person files and stays
responsible.

**Known and left as it is (owner 2026-11-24):** receipts and creditor invoices take the input-tax
rate of the entry or payment date, not of the voucher — wrong only around a change of rate; to be
fixed before the next one (`creditor.md`, `receipt.md`).
