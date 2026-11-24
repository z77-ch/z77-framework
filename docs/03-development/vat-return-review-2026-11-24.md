# VAT return — critical review of the decisions of 2026-11-24 and draft build plan

**Status:** `[APPROVED]` — review done; the owner decided every point of section 3 on
2026-11-24, the addenda are corrected accordingly, section 4 is the build plan.
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
