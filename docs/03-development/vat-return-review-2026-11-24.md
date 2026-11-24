# VAT return — critical review of the decisions of 2026-11-24 and draft build plan

**Status:** `[APPROVED]` — three reviews done; the owner decided every point of sections 3,
6.3 and 7.2 on 2026-11-24, the addenda are corrected accordingly; the build plan is section 4 with
the changes of section 6.6.
**Date:** 2026-11-24

Reviewed: the addenda of 2026-11-24 in [ADR-041](../02-decisions/adr-041-vat-model.md) and
[ADR-042](../02-decisions/adr-042-ledger-and-money.md), from three sides — VAT law, user
handling, build effort. Two independent reviewers; nothing was built.

**Verdict of the review: the addenda as first written were not ready to build.** Two points would produce a wrong or
unlawful return, seven more must be fixed in the first version, and several decided rules
meet a dead end in daily use or contradict the existing code.

## 1. Sources of the legal review

Verified at source unless marked «unverified»:

- MWSTG (SR 641.20) and MWSTV (SR 641.201), state 2025-01-01.
- ESTV MWST-Info 15 (state 2025-02-06) — the effective-method return, field by field.
- eCH-0217 V2.0.0 (approved 2025-09-08) — the electronic return and its plausibility checks.
- ESTV pages on the Korrekturabrechnung, the Jahresabstimmung and the yearly settlement.

The ESTV's own form samples were not reachable; the form was read from MWST-Info 15 and
eCH-0217. Whether the ePortal still shows the old-rate fields in 2026 is unverified.

## 2. Findings

### 2.1 Blockers — the design would produce a wrong or unlawful return

**L1 — No lawful way to correct a settled period** (ADR-042 addendum points 3, 4).
Art. 129 MWSTV: corrections of past returns are filed SEPARATELY from the ordinary returns —
during the year as a Korrekturabrechnung for the period (it replaces the original), after the
year end as the Jahresabstimmung (Art. 72 MWSTG: differences only, due within 180 days of the
fiscal year end). «A late document is dated into the running period» is lawful only for
genuinely later events (an invoice really issued later, a credit note, discount, loss, input
tax claimed later). It is NOT lawful for errors and omissions (a wrong code, a forgotten
invoice of the old period). And year-end entries with VAT cannot be booked at all once the
last period is settled and is no longer the latest return.
→ Keep the freeze, add a separate correction path (section 3, D1).

**L2 — Field 235 as worded fails the ESTV plausibility check** (ADR-041 addendum point 7).
Field 235 takes reductions on TAXABLE supplies only; «negative bases on output codes» also
catches zero-rated and exempt codes. The check «field 299 = sum of the rate fields, to the
Rappen» (eCH-0217, error MWST-0005) then fails.
→ Only the taxable categories go to 235; reductions on zero-rated / exempt codes are netted
into 200 and into 220 / 230. Also reword «field 200 is gross»: in Swiss usage «brutto» means
including VAT — field 200 is excluding VAT, BEFORE reductions.

### 2.2 Must be fixed in the first version

**L3 — The form's tax is base × rate, not the sum of the posted tax amounts.** The ESTV
computes the tax per rate from the base, without rounding in between; only the payable amount
may be rounded. The difference to the ledger is what the rounding account takes. ADR-041
decision 7 («the return is the sum of these lines») must say so. Build to the eCH checks now
(299 = sum of the rate fields; the payable calculation), so the later upload file passes.

**L4 — Settlement periods:** for the effective method the law knows quarter (default), month
(on request) and — since 2025 — YEAR (on request, with three instalments). Semester exists
only for the net tax rate method, which is not built. A change is possible only at the start
of a tax period (1 January). → quarter | month | year. Instalments are bank postings onto the
settlement account, never part of the return. The year-close block must not wait for a
calendar-year return when the fiscal year is not the calendar year.

**L5 — Input-tax corrections and reductions have no source** (fields 415, 420). Mixed use,
own use (private shares) and subsidies reduce the deduction. Without them a mandator with
exempt turnover, subsidies or private use over-deducts. → two more categories, posted through
coded manual entries (the ledger still carries every figure); a hard warning when field 230
is not zero and field 415 is zero.

**L6 — Reverse charge is under-specified** (ADR-041 addendum point 8). Owed and deducted in
the same return is correct, and a liable business has no threshold. Open: (a) the input side
must name field 400 or 405 — «category + rate» cannot tell, the code needs it; (b) under
agreed consideration the debt arises when the INVOICE IS RECEIVED; the creditor direct variant
posts at payment — with full deduction the payable effect is zero, formally the period is
wrong (fiduciary).

**L7 — Field 205 (opted supplies) is missing.** An exempt supply taxed by option is declared
there. → a flag per code, or state «option not supported» and refuse.

**L8 — The reconciliation is too thin for Art. 128 MWSTV.** Required per TAX PERIOD (calendar
year), by rate, with named reconciling items (income booked on expense accounts, sales of
fixed assets, prepayments, reductions, losses, closing entries), plus the VAT accounts against
the declared input tax. «Revenue accounts vs. field 200» alone leaves a permanent unexplained
difference. → a yearly view beside the per-return view; reconcile MOVEMENTS without the
settlement entries, not balances; classify the expected differences, only the unexplained
remainder is red.

**L9 — Lifting a return that was already filed.** The ePortal refuses a second first
submission of a period. The re-created return must show old / new / difference per field and
tell the user what to file. «Only the latest» blocks a first-quarter correction once the
second is posted — solved by the correction path of L1.

**U1 — The refined lock on the VAT accounts breaks the Sammelbuchung.** There the bookkeeper
types the tax-account line by hand today. → the Sammelbuchung writes the tax line itself (as
the one-line form does); the ledger rule: per entry, the lines on each VAT account equal the
tax of the coded lines that map to it.

**U2 — Creditor direct variant: a late voucher paid in a settled period is a dead end.** The
posting date is the payment date — a fact, it cannot be «dated into the running period». The
user loses the input tax or falsifies the date. → «Vorsteuer in laufender Periode nachholen»:
the expense posts at the payment date without VAT, the input tax in the running period as a
tax-only line (the import-VAT line kind).

**U3 — Debtor: an invoice issued with a date in a settled period.** Issuing does not check;
number and PDF carry the old date; «Journal verbuchen» is all-or-nothing, so one such invoice
blocks the batch. → refuse at issue, warn in the draft editor, name the refused document.

**U4 — Opening balances and earlier books on the VAT accounts.** → one rule: before
«Abrechnung ab» the VAT accounts are free and tax codes are refused; from that date only
computed tax lines. Plus a one-off entry offered by the system «Altsaldo MWST auf
Abrechnungskonto übertragen».

**U5 — A mandator that stops being liable loses its last return** (no menu when not liable).
→ a date «Abrechnung bis»; the screen stays while an unsettled period up to it exists.

**U6 — First setup on an existing installation.** Balances on the VAT accounts at «Abrechnung
ab» and settlements already posted by hand make the reconciliation wrong for ever. → a setup
step: the date must be a period start; it shows the balances the day before and the manual
entries since, and offers the carry-over.

### 2.3 Medium — handling

- **U7 What to type into the ePortal:** the PDF prints per field what to type, the tax «as the
  ESTV computes it» beside «as posted», and the difference.
- **U8 Warnings at «MWST buchen»:** the bank check yields WARNINGS under `vat-return`; reuse the
  confirmation with fingerprint and the protocol of the year close. Blocking findings show in
  the preview already, with a link to the screen that clears them.
- **U9 Order «posted = filed»:** the screen says «zuerst im Portal einreichen, dann MWST
  buchen»; an optional date «eingereicht am» and the archived portal receipt.
- **U10 Lift chain:** correcting the first of three posted returns is 7 actions and 6 extra
  entries; across a year end about 10. → one admin action «ab Periode X aufheben» (cascade from
  the latest, one reason) and «neu erstellen bis heute». Lifting needs the year open.
- **U11 Year end:** the help teaches the pattern (transitory account without a code on the
  last day, the voucher with VAT in the next period). A VAT period may span two fiscal years.
- **U12 Uncoded amounts through generated postings:** the lock stops manual entries only; the
  account pickers of bank / receipt / creditor must not offer the VAT accounts.
- **U13 Capture of reverse charge and import VAT:** «Betrag» is gross everywhere today — for
  reverse charge it is the amount paid, the tax comes on top on both sides; import VAT as an
  option of the tax-code select, not a separate switch; forwarder invoices mix line kinds.
- **U14 Payment corrections after the return** (reallocation with discount VAT, a bank
  take-back): offer «reverse and re-post in the running period».
- **U15 The category is read at return time**, but a code's category can be changed later —
  against «computed once» (ADR-041 decision 6). → snapshot the mapping per return, or freeze a
  code's category once used.

### 2.4 Lawful limits the user must be told (help, not code)

Input tax at payment (allowed without approval); import VAT evidence and the deferral
procedure (Verlagerungsverfahren — not supported, refuse); fields 221 / 225 / 280 / 410 / 900 /
910 not built (910 is a declaration duty — typed in the portal by hand); a mandator that is
not liable still owes reverse charge above CHF 10 000 a year; prepayments without an invoice;
foreign currency rates; recovery of a written-off receivable; start and end of liability, the
final return; retention counted from the end of the calendar year.

### 2.5 Contradictions a builder would stop at

1. Refined lock vs. the hand-typed tax line of the Sammelbuchung (U1).
2. «Tax lines before „Abrechnung ab“ refused» vs. the import of the old system's tax lines
   «as booked» (ADR-041 Consequences) and entering prior years afterwards.
3. Direct variant vs. «date into the running period» (U2).
4. Reverse charge has no input account or field (`vatAccountFor()` returns null for it).
5. A tax-only line vs. «tax data on the NET line only» and vs. the lock: is a coded line ON a
   VAT account «computed» or «typed»?
6. «Settled from the returns» has no lock: the race guard is built on the period state
   (`PostingRules`, `lockedPeriodState`).
7. Lift vs. idempotency (the re-created entry needs a revision in its key) and vs. a closed
   year (a reversal into a closed period is refused).
8. `VatSettled` is read outside financial (creditor, receipt, two controller traits, two
   templates, two harnesses) — dropping it needs a public financial answer first.
9. Stale texts: `financial.md` lines on `vat-settled`, bauplan §5.3 / §5.6 (still the
   2026-09-30 rule), comments in `Period.php` / `PeriodState.php`.
10. `MandatorAccountsCheck` checks every default key regardless of liability — two new keys
    would nag mandators that are not liable.

## 3. Owner decisions (2026-11-24)

| # | Question | Decided |
|---|---|---|
| D1 | The correction of a settled period (L1, L9, U10) | The freeze stays, plus the correction path: an admin «Korrekturbuchung zu Periode X», never part of an ordinary return; a correction return per period (difference, own settlement entry, what to file). Lifting stays for a return posted by mistake and not yet filed. |
| D2 | Field 235 for taxable categories only; «200 before reductions» (L2) | Yes. |
| D3 | Form tax = base × rate, difference to the rounding account (L3) | Yes. |
| D4 | Rhythm (L4) | quarter, month, year; a change on 1 January only. |
| D5 | Input-tax correction and reduction, fields 415 / 420 (L5) | NOT built. A private share is invoiced to the person as a taxable supply (the practice of the owner — the way of a company; the own use of a sole proprietor belongs in 415, fiduciary); the return warns on exempt turnover; the help says so. |
| D6 | Reverse charge: field 400 or 405 (L6) | Two seed codes. The date of the debt: fiduciary. |
| D7 | Option, field 205 (L7) | Not built; the help says so — the user adds it in the portal. |
| D8 | Yearly reconciliation view, Art. 128 MWSTV (L8) | Yes, as its own stage. |
| D9 | The Sammelbuchung writes the tax line itself (U1) | Yes. |
| D10 | «Vorsteuer nachholen» for the direct variant (U2) | Yes. |
| D11 | An invoice dated into a settled period (U3) | Refused at ISSUE; the draft warns. |
| D12 | «Abrechnung ab» / «bis», VAT accounts free before it, carry-over entry, setup step (U4–U6) | All four. |
| D13 | Build order | Core first, foreign supplies after — section 4. |

## 4. Build plan

One session = about 3–4 hours of the owner with an AI builder: one coherent slice, reviewed,
tested with its own harness. The full `finance` group run comes on top once per stage. The
return gets a NEW harness (`financial-vat-return`, group `finance`) — it runs beside the
longest harness, so the wall time stays where it is. A board on the developer canvas precedes
every screen (Key Rule 8). Every deployed stage carries the manual check list for the owner.

| Stage | Built | Risk | Sessions |
|---|---|---|---|
| **S0** Verify | Fiduciary on section 5; the boards for S1; the builder stops of 2.5 that S1 meets | — | 1–2 |
| **S1** Read-only return + PDF (first useful stage) | Rule file (category + rate → field, versioned, exceptions, unmapped refuses; fields 200, 220, 230, 235, 299, the rate fields old and new, 399, 400, 405, 479, 500 / 510); mandator fields method / rhythm / «Abrechnung ab» / «bis» (migration, tab, validator, setup check for a liable mandator); period calculator (grid, short first period, across fiscal years); aggregate of the bases per code + rate; tax = base × rate with the eCH checks; the list, the return page (tabs Formular, Herkunft, Abstimmung), the drill-down (needs a tax-code filter on the journal search); open-work findings shown; PDF with «what to type»; warns on exempt turnover; refuses a return holding reverse-charge lines; menu entry only when liable; help | low — writes nothing | 5–8 |
| **S2** Posting | `VatReturn` entity (method and mapping snapshot, declared figures per field, entry reference, state, filed date); mandator keys settlement account and rounding account, 6945 in the KMU chart; settlement entry; «MWST buchen» with findings and confirmed warnings; the lock design for «settled»; `PostingRules` asks the returns + the «Abrechnung ab» rule; `vat-settled` dropped behind a public financial answer (creditor, receipt, two controller traits, two templates, two harnesses); year-close check; debtor refuses at issue; «reverse and re-post» for payment corrections; lifting (latest, admin, cascade, key revision); the setup step with the carry-over entry | high — locks, four modules | 7–11 |
| **S3** Correction path | «Korrekturbuchung zu Periode X» (admin, marked, excluded from the ordinary aggregate); correction return per period with its settlement entry; the print old / new / difference and «what to file» | high — new concept | 4–7 |
| **S4** VAT-account lock | The Sammelbuchung writes the tax line; the ledger matching rule; backfill of the flag on the VAT accounts of the mandator; free before «Abrechnung ab»; the pickers of bank / receipt / creditor exclude the VAT accounts | medium-high — changes daily work | 4–6 |
| **S5** «Tax only» line | Line kind on receipt and creditor lines (migrations), the journal-line invariant, capture as an option of the tax-code select, recognition; import VAT and «Vorsteuer nachholen» | medium | 3–5 |
| **S6** Reverse charge | Two seed codes, the sign rule, two tax lines per coded line, `vatAccountFor()`, receipt and creditor capture («Betrag» = amount paid), the rule rows | medium | 3–5 |
| **S7** Finish | The yearly reconciliation view (Art. 128), the dashboard due line, every «folgt» help text, README, docs consolidation (the stale texts of 2.5), changelog | low | 2–3 |

**Total about 29–47 sessions.** After S3 (about 17–28) the return is complete and lawful for
an installation without imports and without services bought abroad. S4 must not ship before
S5 — otherwise import VAT has no way into the books. Until S6 the return refuses when
reverse-charge lines exist; z77 cannot see an UNCODED foreign service, so the help asks the
question per installation.

## 5. For the fiduciary

1. May an error found within the same tax year go into the next ordinary return, or always a
   Korrekturabrechnung?
2. Reverse charge dated at payment (direct variant) when no tax is lost?
3. Late input tax across a tax-year boundary in an ordinary return?
4. Private shares: field 415 or as turnover?
5. Bank interest income: field 230 or 910 — and does it force an input-tax correction?
6. Does the ePortal accept a negative base per rate?
7. Which reconciliation do auditors expect under Art. 128 — per tax year only, or per return?
8. Does any installation use the deferral procedure, the notification procedure or the option?
9. Reductions in field 235 or netted into 200 — a house preference?

## 6. Second review (2026-11-24) — the corrected addenda, checked at the primary sources and by calculation

Two further independent reviewers read the CORRECTED addenda (commit `5afa3c8`): one against
the primary sources, one by computing 17 concrete cases from the journal shapes the code
writes. **Verdict: still not ready to build as written.** The core mechanics hold at source
(field 235, base × rate, the rhythms, the freeze, reverse charge within one return, input tax
at payment). Open owner decisions are marked **→ owner**; the rest are corrections of wording
or rule. All of it is worked into the addenda (third version of the day).

Sources read on 2026-11-24, at the publisher: MWSTG (fedlex, consolidation 2025-03-31), MWSTV
(fedlex, consolidation 2026-10-28), GeBüV, OR, the ESTV pages on the Jahresabstimmung and the
Korrekturabrechnung (updated 2026-11-05), the ESTV web publications MWST-Info 04, 07, 08, 09,
14, 15, 16 (the cited chapters; 08 and 15 in full), eCH-0217 V2.0.0. Not read: MWST-Info 05,
06, 10, 11, 12. The behaviour of the ePortal screens is unverified throughout.

### 6.1 Law — new findings

**R1 — The dunning fee is consideration (BLOCKER, concerns code that is shipped).** MWST-Info
04, 3.8.2.3: «Mahnspesen, die eine steuerpflichtige Person von säumigen Zahlern erhebt, sowie
vertraglich vereinbarte Zinsen gehören zum Entgelt der erbrachten Leistung und bilden somit
Bemessungsgrundlage der MWST. Verzugszinsen (Art. 104 OR) hingegen gelten als Ersatz des
Schadens.» Today the fee posts without a tax code — for a liable mandator it is missing from
field 200 and from the rate field, and nothing shows it. → the fee of a liable mandator
carries the tax code(s) of the dunned invoice; statutory default interest and collection
costs stay without a code. **→ owner** (and the fiduciary: the rate when the invoice mixes
rates).

**R2 — A Korrekturabrechnung REPLACES the original in full; only the Jahresabstimmung takes
differences (BLOCKER).** eCH-0217 3.1: «Die Korrekturabrechnung wird wie die Ersteinreichung
vollständig ausgefüllt und ersetzt die Ersteinreichung». The Jahresabstimmung declares «nur
die Differenzen», ONE per tax period, and cannot be corrected afterwards. The addendum
(ADR-042 point 4) says «difference» for both. → per filing channel: (a) Korrekturabrechnung
for period X — the print shows the COMPLETE new figures, the difference beside them;
(b) Jahresabstimmung — one per tax period, the sum of the differences not already filed
under (a). Each correction return stores its channel; its print lists the fields z77 does not
carry, to be re-entered from the original. The settlement entry is the difference in both.

**R3 — «After the year end» is the wrong switch.** The tax period is the CALENDAR year (Art.
34 para. 2 MWSTG; the business-year variant is not in force). The deadline of Art. 72 para. 1
runs from the BUSINESS-year end: the return of the period in which the 180th day falls; the
ESTV finalises after 240 days. After that a Korrekturabrechnung stays possible up to five
years. → the channel follows from the date; the screen shows the 180- and 240-day dates.

**R4 — A correction entry cannot be «dated into the settled period» once that fiscal year is
closed** — the law obliges corrections for years after the accounts are approved, and
reopening an approved year changes closed books. → a correction entry carries TWO dates: the
posting date (an open period) and the VAT period it corrects; the correction return selects by
the latter.

**R5 — Fields «added in the portal by hand» break the form or the books.** 221 / 225 / 280 are
deductions FROM field 200 and must be contained in it; 410 / 415 / 420 change the payable
amount — the filed amount then differs from the settlement entry for good, and the lock
refuses the entry that would mend it. → supplies abroad get a seed code mapped to field 221;
the help says for 225 / 280 «add to field 200 AND to the field»; a field that changes the
payable amount is never added by hand without a booking.

**R6 — Input-tax correction, fields 415 / 420 (first review L5, decided «not built»).**
MWST-Info 08, 2.1: for withdrawals by the OWNER of a sole proprietorship there is no supply —
it is an input-tax correction, field 415. The owner's practice (invoice as a taxable supply)
conforms for staff, shareholder-employees and board members (Art. 47 MWSTV). Also needing
415 / 420: mixed use, subsidies, gifts above CHF 500, deregistration. The smallest lawful
build: two categories posted only by a coded manual entry, no calculation in z77. Otherwise
those users are excluded (6.4). **→ owner, raised again.**

**R7 — Supplier input tax is computed at the rate of the payment / entry date (concerns code
that is shipped).** Deductible is the tax INVOICED (Art. 28 MWSTG); the rate follows the
service date. A December service at the old rate paid in January is deducted at the new one.
→ the rate of a supplier invoice or receipt is the rate on the voucher: resolved by the
voucher date, with the other rate of the code selectable. **→ owner.**

**R8 — Uncoded revenue and uncoded foreign services must WARN, not only reconcile.** Field
200 holds all consideration. → at «MWST buchen» a warning to confirm lists every line on a
revenue account without a tax code (except the accounts named for invoice rounding, default
interest and non-consideration) and every supplier voucher in a foreign currency or from a
foreign address posted without VAT.

**R9 — Reverse charge: date, rate, base.** The debt arises at receipt of the invoice (Art. 48
para. 1 lit. b) — the two tax lines post at the INVOICE date also in the direct variant; the
reduced rate exists (the acquisition-tax field per rate); the base is the invoice amount
without foreign VAT, converted at the ESTV rate — not the amount debited with bank fees.

**Limits to state in the help:** import VAT is dated by the customs assessment (its evidence),
not the forwarder's invoice; «Vorsteuer nachholen» ends five years after the tax period and is
reversed if the invoice is never paid; liability may start mid-period (a short first period —
«Abrechnung ab» is then not a grid start); the conditions of the monthly and the yearly
rhythm; the method has no silent default — unset refuses, the method is printed on every PDF;
one rate per document (a service period across a rate change must be split); prepayments and
cash without a document; value vouchers; lifting is refused while a filed date is set; not
carried: fictitious input tax, the reduced deduction on farm purchases, platform supplies,
group taxation, travel agents.

**Corrections of the first review:** the 180 days are not a plain deadline (R3); «errors never
in the running period» is the strict reading — eCH-0217 7.4 lists the next ordinary return as
a channel too, and a wrongly invoiced rate is corrected by a correcting DOCUMENT in the
running period (fiduciary); «a change only on 1 January» is statute for the yearly rhythm
only; the reconciliation of Art. 128 MWSTV «may be requested» and may follow the business
year; retention is ten years from the end of the business year (OR) and until absolute
prescription for VAT, twenty for real estate. Answered at source: bank interest is exempt
turnover (fields 200 and 230), no input-tax correction up to CHF 250 000 a year — the planned
warning «230 without 415» must name this.

### 6.2 Calculation — rule defects found in 17 worked cases

- **C1 Reverse-charge sign (BLOCKER).** With today's convention the expense line carries a
  negative base and field 383 comes out negative. → reverse-charge codes are debit-natural
  like input codes; one coded line maps to TWO tax lines (credit VAT owed, debit the input
  account of its code).
- **C2 The refined lock refuses the return's own entries (BLOCKER).** → exempt from the lock:
  the entries of the source `vat-return` (settlement, correction settlement, their reversals)
  and every entry dated before «Abrechnung ab».
- **C3 Where the form tax is rounded.** Rounding each rate field and summing gives another
  payable amount than rounding once (case 1: 667.84 vs. 667.83). → the tax per rate field is
  kept exact and shown; the total owed is the sum of the exact products; the payable amount is
  rounded ONCE; the settlement entry posts that amount (eCH-0217 6.2.1, 7.5).
- **C4 The input fields are the POSTED tax,** not base × rate — a voucher tax differs by a
  Rappen, and a tax-only line has base 0. Base × rate applies to the rate fields and the
  acquisition-tax fields only.
- **C5 The reverse-charge share of the input field equals the tax DECLARED in the
  acquisition-tax field** — otherwise three invoices of 100.05 leave 0.01 payable instead of
  zero.
- **C6 Field 235 by ORIGIN, not by sign.** By sign, a cancelled invoice inflates 200 and 235
  (case 6: 2 918.46 / 2 218.46 instead of 700.00 / 0.00), and a code correction declares a
  reduction that never happened. → 235 takes credit notes, discount and loss allocations; a
  reversal pair and correction entries are netted into 200 per code + rate.
- **C7 The input categories map at ANY rate** — otherwise a purchase at the reduced rate is
  unmapped and refuses the whole return.
- **C8 The reconciliation formula.** Field 200 cannot be reconciled with the revenue accounts
  (credit notes post on them). What reconciles: field 200 − 235 against the revenue movements,
  with the named items uncoded lines per account, coded output lines on other account types,
  correction entries of the period (case 7: 401.68 = 371.65 + 0.03 + 30.00).
- **C9 The settlement entry:** dated the last day of the period; it clears each VAT account by
  the coded tax of the return; a gap to the account's movement is a BLOCKING finding, never
  the rounding account.
- **C10 An existing defect in `PaymentPostingBuilder::sharesPerCode()`:** an invoice rounded
  down and then written off in full leaves 0.04 of base as turnover (the `abs()` on a negative
  uncoded share). Reported by the reviewer from the code, not yet reproduced — a fix of its
  own with a test.
- To decide or state: negative rate fields and a negative field 220 arise in normal work
  (a large credit note for an earlier period) — pass through or refuse; the rounding
  difference grows with the square root of the number of documents (finding above 0.0055 per
  coded document); the carry-over entry is dated the day BEFORE «Abrechnung ab»; a balance
  check «the VAT accounts are zero after each settlement»; «Vorsteuer nachholen» has no
  counter account yet.

Builder questions left: «negative base» per line, per document or per period; the dates of
the correction settlement and of the reversal; the base and rate snapshot of a tax-only line;
a second correction of one period («now − original» or «now − last filed»); whether a
correction return counts in the «latest» order of lifting; which account a correction entry
for a forgotten debtor invoice uses (the receivable account is module-only).

The 17 cases with their figures are the golden tests of the harness (`financial-vat-return`).

### 6.3 Owner decisions on the second review (2026-11-24)

| # | Question | Decided |
|---|---|---|
| E1 | Dunning fee of a liable mandator (R1) | With VAT, the tax code of the dunned invoice (mixed rates: its largest share). A fix of its own, ahead of the return — DEBTOR-FEE-VAT-001. |
| E2 | Input-tax correction and reduction (R6) | The smallest build: two categories, posted by a coded manual entry, nothing computed. Reverses D5. |
| E3 | Supplier input tax at the rate of the voucher (R7) | Left as it is, recorded as a known issue, to be fixed before the next change of rate — CREDITOR-VAT-RATE-001, RECEIPT-VAT-RATE-001. |
| E4 | The correction path per channel, two dates (R2–R4) | Yes. |
| E5 | A seed code for supplies abroad, field 221 (R5) | Yes. |
| E6 | Warnings for uncoded revenue and possible reverse charge (R8) | Yes. |
| E7 | Reverse charge at the invoice date, per rate, base without foreign VAT (R9) | Yes. |
| E8 | The calculation rules C1–C9 | Yes. |
| E9 | Negative fields | Shown with a warning, not refused. |

### 6.4 For whom the first version is NOT suitable (README and help)

A business that: settles by net tax rate, flat rate or received consideration; is a sole
proprietorship or partnership with private use or withdrawals in kind; has exempt turnover
needing an input-tax correction, or receives subsidies; imports under the deferral procedure,
or imports at all before the tax-only stage; buys services abroad before the reverse-charge
stage, or with partial deduction; uses the notification procedure, group taxation, fictitious
input tax, platform or travel-agent rules; starts or ends its liability with assets on hand;
invoices in a foreign currency; takes prepayments or cash without a document, or sells value
vouchers; invoices one service period across a rate change on one document; is not liable but
buys more than CHF 10 000 of foreign services a year.

Stated generally: supported are the effective method with agreed consideration, by quarter,
month or year. The return is an aid to self-assessment — the taxable person files in the
portal and stays responsible. No tax advice. No approval or certification of bookkeeping
software exists; never advertise «conforms to the law», only the supported scope. The
operator owes the archive on an integrity-protected medium and the procedure documentation
(GeBüV).

### 6.5 Further questions for the fiduciary

10. After the Jahresabstimmung is filed and a further error of that year appears — a
    Korrekturabrechnung of the single period?
11. Which form does a correction take under the yearly rhythm?
12. Dunning fee: the rate with mixed rates; a flat fee per notice.
13. Contributions of staff to meal vouchers — a taxable supply at all? (MWST-Info 08, 1.3:
    Lunch-Checks have no VAT effect, only canteen payments.)
14. Sole proprietor: the private share as turnover instead of field 415 — tolerated?
15. The 0.05 rounding line of an invoice without tax.
16. For a lawyer, not a fiduciary: how far the licence disclaimer protects under Swiss law.
17. Not checked: whether the issued invoices print every element of Art. 26 para. 2 MWSTG.

### 6.6 Changes to the build plan of section 4

- **Ahead of S1, each a fix of its own with a test:** the dunning fee with VAT (E1), and the
  reported split error of a full loss (C10 — reproduce first).
- **S1** also carries: the two input-correction categories and the seed code for supplies
  abroad in the rule file; field 235 by origin; the exact tax with one rounding; the input
  fields as posted; the warnings of E6; negative fields shown; the 17 golden cases.
- **S3** is the correction path per channel with two dates — larger than first planned.
- **S4** carries the exemptions of the lock and the two correction categories as coded
  manual entries.
- **S6** posts the reverse-charge tax lines at the invoice date, also in the direct variant.
- **S7** adds README and help with the list of 6.4.

The estimate of section 4 grows by about 3–5 sessions: **about 32–52 in all**, after S3 about
19–31.

## 7. Third review (2026-11-24) — the publications not read before, and the issued documents

Two further read-only checks closed the gaps section 6 named. Read at the publisher on
2026-11-24: ESTV MWST-Info 02 (Steuerpflicht), 05 (Subventionen und Spenden), 06 (Ort der
Leistungserbringung), 07, 10, 11, 14, 15, 16, 19 in full, 04 and 09 by chapter, 12 in part;
the eCH-0217 schema. **No blocker against the decided rules;** six points to fix, one defect in
shipped code, one in the issued documents. Still not read: MWST-Info 13 and 22, the
MWST-Branchen-Infos. The portal screens stay unverified — no public user documentation exists
for what is typed per field, how the portal rounds, or how a correction is opened.

### 7.1 The issued documents (Art. 26 MWSTG)

- **The invoice PDFs did not print the mandator's VAT number** — `pdf/letterhead` of
  module-mandator omitted the line its HTML sibling prints; Art. 26 para. 2 lit. a asks for the
  number on the invoice of a liable business. Fixed the same day (MANDATOR-PDF-UID-001,
  `mandator.md`), with the owner's rule: the number is a mandatory field while «MWST-pflichtig»
  is on and may be missing otherwise; the invoice print and the dunning print handle all three
  cases. Archived PDFs are not rendered again.
- Every other element is present on invoice, credit note and fee document: recipient (issuing
  refuses an incomplete address), service date or period, kind and extent, consideration, rate
  and tax per rate, the reference of a credit note to its invoice; a mandator that is not
  liable prints nothing VAT-like.

### 7.2 New findings

- **T1 Fees of a bank or payment provider are not a reduction of consideration (shipped
  code).** A payout net of commission can be cleared today only as «Skonto» or «Verlust» —
  both reduce turnover and VAT. MWST-Info 07, 1.1: «Das steuerbare Entgelt ist brutto, d. h.
  vor Abzug dieser Kosten, zu verbuchen.» → an allocation kind of its own for fees, posted to
  an expense account without a tax code. **Decided 2026-11-24: built, a fix of its own ahead of
  the return** (DEBTOR-FEE-KIND-001, `debtor.md`).
- **T2 The portal asks first whether turnover is declared gross or net.** → every return and
  its PDF state «Deklaration netto (ohne MWST)»; the help says to choose «netto».
- **T3 «Abrechnung ab» and input tax.** Founding costs up to six months before the
  registration are deductible in the first return (MWST-Info 09, 1.8) — claimed as a «tax
  only» line dated in the first period. And an input code on a voucher for a service rendered
  BEFORE «Abrechnung ab» is not deductible (1.7) — a warning at «MWST buchen».
- **T4 Sales of fixed assets** are the typical omission (MWST-Info 16, 3.1). → the warnings
  also list a credit line without a tax code on a fixed-asset account, and income booked on
  expense accounts.
- **T5 Field 221 is only for supplies abroad that would be taxable in Switzerland;** an
  exempt-type supply abroad belongs in field 230 (MWST-Info 15, 1.3). One seed code for 221
  suffices; its name and help say «im Inland steuerbar».
- **T6 The rhythm change «on 1 January only» is stricter than the law for the month:** the
  1 January rule is that of the yearly settlement (Art. 35a MWSTG). → to or from the year: on
  1 January; between quarter and month: at a quarter start. The yearly rhythm may start with
  the liability itself — a short first period there too.
- **T7 Retroactive registration** (to 1 January, or to the start of the activity): «Abrechnung
  ab» may lie before today; revenue posted since without a code is listed at setup and
  declared by coded manual entries. A limit to state.
- **T8 Field 410 (Einlageentsteuerung) has no category** — entry into the liability with
  assets on hand, a switch from the net tax rate method, an option for property. The same
  cheap mechanism as fields 415 / 420 carries it. **Decided 2026-11-24: a third category, posted
  by a coded manual entry.**
- **T9 Three methods, not two:** the flat tax rate method is one of its own. The method field
  names it; everything but effective + agreed refuses.
- **T10 The settlement entry needs its voucher:** MWST-Info 16, 1.3 counts the compilation for
  a return among the accounting vouchers, and 1.5 asks for the trail «vom Einzelbeleg … bis
  zur MWST-Abrechnung als auch in umgekehrter Richtung». → on posting, the PDF with the
  breakdown per field and account is archived with the settlement entry.
- **T11 The yearly reconciliation** follows MWST-Info 16, 3.3: A = the books by rate, B = the
  figures DECLARED on the filed returns, C = A − B = what is still to declare. The yearly view
  compares with the stored declared figures, corrections included.

### 7.3 Confirmed at source

The dunning fee as consideration (MWST-Info 04, 3.8.2.3 and 07, 1.1); reductions in the
running period (Art. 41 MWSTG) and at the OLD rate of the original supply (MWST-Info 19, 2.5 —
the code does this); field 235 or netting into field 200, both lawful — fiduciary question 9
is answered; negative entries in rate fields are prescribed by the ESTV itself for the rate
change (MWST-Info 19, 5) and the electronic return allows them — question 6 is answered at
source level; input tax at payment without approval; «Vorsteuer nachholen» until prescription,
and the deduction is reversed if an invoice is never paid; import VAT by the customs
assessment; reverse charge in the same return, base without foreign VAT, the rate the ESTV
publishes; no input-tax correction for interest income up to CHF 250 000 a year (sales of
securities count in). Corrected: records on real estate are kept 26 years by ESTV practice;
the EXIT from the liability is possible with the correction category of 6.3 E2 — only the
entry needs field 410.

### 7.4 Additions to «not suitable for» (6.4)

Payouts net of provider fees (until T1 is built); retroactive registration with invoices
already issued without VAT; founding costs before registration (until T3); the flat tax rate
method and settlement periods granted individually; margin taxation and the sale of property
with land value; the transfer of a business or of assets at book value (the notification
procedure can be MANDATORY there); supplies to closely related persons below market value;
emission rights and certificates.

### 7.5 Everyday cases for the help

Shipping and packaging follow the main supply; re-invoiced expenses take the code of the main
supply; a package price follows its predominant part only from 70 %; dunning fees and agreed
interest with VAT, statutory default interest and collection costs without; damages without a
code; a value voucher without VAT at sale; a card surcharge charged to the customer is
consideration; the private use of a company car by staff (0.9 % of the purchase price a
month, VAT included); gifts above CHF 500 a person and year; a receipt saying only «inkl.
MWST» gives no input tax; foreign VAT on a supplier invoice is never input tax; a foreign
software invoice with Swiss VAT and a CHE number is ordinary input tax, without it reverse
charge; a foreign customer is not automatically «abroad»; the sale of a business vehicle is a
taxable sale; an invoice never paid — reverse the input tax; the last return within 60 days
of the end of the liability.
