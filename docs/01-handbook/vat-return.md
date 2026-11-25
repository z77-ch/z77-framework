# The VAT return

**Status:** `[CURRENT]`
**Last updated:** 2026-11-25

How the Swiss VAT return (MWST-Abrechnung) works in z77 from end to end — for a successor developer
and for a fiduciary reading along. One worked example per rule; the code, the refusal sentences and
the tests live in the topic docs and ADRs linked in [section 14](#14-where-to-read-further). Screen
labels in «…» stand exactly as on screen.

> **Keine Steuerberatung.** z77 gives no tax advice. The return is an aid to self-assessment: the
> taxable person files in the ESTV ePortal and stays responsible for what is filed. z77 states the
> scope it supports — it never claims to «conform to the law». Where a rule below rests on an
> article of law, the ADRs name it as the decision notes give it.

## 1. What z77 settles

- **One method:** the effective method with **agreed** consideration («Effektive Methode ·
  Vereinbartes Entgelt»), by quarter, month or year. Both axes are fields on the mandator; a
  mandator set to the net tax rate method, the flat tax rate method or **received** consideration —
  or with nothing set — gets a refused return («nicht unterstützt»), never an effective / agreed
  return under another name.
- **The form:** ESTV form 0535, edition 01.24, for periods from 2024 on. Periods before 2024 have no
  edition in z77 and are refused.
- **The source is the journal.** Every figure comes from the tax data on journal lines (code, rate,
  base, tax). No figure of a return is ever typed into z77; what the ledger does not carry is not in
  the return.
- **z77 does not file.** The figures are typed into the ePortal by hand («Deklaration netto (ohne
  MWST)» — the portal asks gross or net first; choose net). There is no upload file yet.

## 2. The principle: «gebucht = eingereicht»

**Posted means filed.** The user files in the ePortal first, then clicks «MWST buchen» in z77. There
is no «Eingereicht am», no fourth state, no filed date: the posting is the filing.

What follows from it:

- **A posted period is frozen.** It is never reopened for a later event or for an error.
- **A later event belongs to the running period** — a credit note, a discount, a loss, a late
  supplier invoice. It is posted with its own date in the first period not yet posted.
- **An error in a filed return is reported to the tax office.** z77 records it as a correction entry
  in the running period and lists it apart («Korrekturen früherer Perioden — separat im ePortal
  melden», [section 7](#7-errors-in-a-posted-period-the-correction-entry)).
- **Lifting is an emergency brake**, not a correction tool — for a return posted by MISTAKE
  ([section 4](#4-the-life-of-a-period)).

## 3. Setting it up — the mandator

Stammdaten › Firma › Mandant. The rules: [`mandator.md`](../topics/mandator.md) «The VAT return's
settings».

**Tab «UID und MWST»** (shown while «MWST-pflichtig» is on):

| Field | Meaning |
|---|---|
| «Abrechnungsmethode» / «Abrechnungsart» | effective / agreed — the only pair z77 settles |
| «Abrechnungsrhythmus» | quarter, month or year |
| «Rhythmus gilt ab» | the 1 January a changed rhythm applies from; empty = since «Abrechnung ab» |
| «Abrechnung ab» | the first day z77 settles — a period start, or the day the liability began (a short first period) |
| «Abrechnung bis» | the end of the liability in z77; empty while liable |

- **Before «Abrechnung ab»** nothing is settled in z77: a new posting with a tax code dated before
  that day is refused; a voucher of that time is posted «ohne MWST». Nothing before the day is caught
  up later. Without «Abrechnung ab» a liable mandator settles outside z77 — nothing blocks, the year
  close shows a hint only.
- **After «Abrechnung bis»** the return screen stays until every period up to that day is posted;
  the lock of the VAT accounts is off, a correction entry is refused («… die Korrektur direkt im
  ePortal melden») — no return would carry it.
- **«Rhythmus gilt ab»** — z77 accepts a rhythm change on a 1 January only, and only when every
  period before it is posted (narrower than the law, by decision: no return is cut in two). The
  earlier years keep their rhythm; the line under the field shows it.
  *Example:* quarterly since 2026, monthly from 2028 → «Abrechnungsrhythmus» Monat, «Rhythmus gilt
  ab» 01.01.2028, the save is accepted only once 4. Quartal 2027 is posted; the field then reads
  «Davor: Quartal bis 31.12.2027».
- Once a return is posted, moving «Abrechnung ab» or changing method, consideration or rhythm
  (other than through «Rhythmus gilt ab») is refused.

**Tab «Konten»** — the accounts the return posts on (KMU numbers; a new record starts with them, an
existing one names them by hand):

| Account | Role | Locked? |
|---|---|---|
| 2200 «Geschuldete MWST (Umsatzsteuer)» | VAT owed | yes |
| 1170 «Vorsteuer MWST Material, Waren, Dienstleistungen, Energie» | input tax, field 400 | yes |
| 1171 «Vorsteuer MWST Investitionen, übriger Betriebsaufwand» | input tax, fields 405 / 410 / 415 / 420 | yes |
| 2201 «Abrechnungskonto MWST» | settlement account — the payable amount, the payment to the ESTV, instalments | no |
| 6945 «Rundungsdifferenzen MWST» | the Rappen between declared and posted tax | no |
| 1179 «Vorsteuer, noch nicht abgerechnet» | transit for input tax caught up ([section 8](#8-the-two-date-rules--kept-apart-on-purpose)) | no |
| 2209 «MWST-Korrektur, noch nicht abgerechnet» | transit for the year end ([section 10](#10-the-year-end--account-2209)) | no |

One account may not serve as VAT owed and input tax at once; 2209 must be an account of its own.
Old balances of the VAT accounts from before «Abrechnung ab» disturb nothing (the return reconciles
the movements of the period); carrying them onto 2201 is an optional manual entry dated before that
day — there is no setup page.

## 4. The life of a period

```text
open ──«MWST buchen»──▶ posted (settled) ──«Aufheben …» (admin, latest only)──▶ open again, revision n+1
```

**The running period** («laufende Periode») is the FIRST VAT period not yet posted — not necessarily
the current calendar period. *Example:* on 10.05.2027 with 4. Quartal 2026 posted and 1. Quartal 2027
not yet, the running period is 1. Quartal 2027; everything «caught up» lands on 01.01.2027.

1. **Open.** Finanzen › MWST-Abrechnungen lists one row per period ending in the fiscal year. The
   action cell «Neue Abrechnung» opens the next elapsed open period; the dashboard names the return
   that is due («MWST-Abrechnung {Periode} fällig bis {Datum}» — period end + 60 days). The return
   page computes everything live from the journal and writes nothing; it can be read and printed
   any number of times.
2. **«Offene Punkte».** Blocking points first (a posting not yet journalised in debtor, creditor or
   receipt, a VAT account whose movement differs from the coded tax, a missing account, …), then
   warnings (revenue without a tax code, a credit on a fixed-asset account, reverse charge with full
   deduction, import VAT across periods, …). While anything blocks, the action cell is EMPTY and the
   first open point says why, with its link.
3. **«MWST buchen …»** — only after filing in the ePortal. The window shows the payable amount, the
   rounding, the settlement entry line by line, and the warnings, confirmed with ONE tick «Warnungen
   gelesen — trotzdem buchen». A nil return goes through the same window («Buchung: keine»).
4. **Posted.** The settlement entry is in the journal, the voucher PDF in the write-once archive, a
   row in the protocol «Abschlüsse und MWST». The return page shows the STORED figures; «Herkunft»
   and «Abstimmung» stay computed and say «Seit dem Buchen geändert — der Beleg zeigt den Stand vom
   {Datum}.» when the books moved since.

**The settlement entry** is dated the period's last day, generated, without a tax code. It clears
each VAT account by the coded tax of the return against 2201 and puts the Rappen difference on 6945.

*Example* — 3. Quartal 2026: revenue 10'000.00 with UN (8.1 %), purchases 2'000.00 with VM.

```text
Form:    200  10'000.00    299  10'000.00    303  base 10'000.00 · tax 810.00
         399     810.00    400     162.00    479     162.00     500  648.00

Settlement entry 30.09.2026 «MWST-Abrechnung 3. Quartal 2026»
  Dr 2200 Geschuldete MWST        810.00
     Cr 1170 Vorsteuer Material             162.00
     Cr 2201 Abrechnungskonto MWST          648.00
```

The payment to the ESTV later goes from the bank onto 2201.

**The freeze.** A settled period refuses every posting with a tax line — new, changed, deleted,
reversed, manual or generated by a module. An entry WITHOUT a tax code stays possible while the
fiscal period is open. *Example:* editing an invoice line dated 15.08.2026 after Q3 is posted →
«Die MWST-Periode 3. Quartal 2026 ist abgerechnet — eine Buchung mit MWST-Code geht dort nicht
mehr.»; the journal shows the entry with a grey lock and opens it read only. Debtor refuses to issue
a document into the period, the bank refuses a take-back that would change it.

**The year close** is blocked while a VAT period ending in the fiscal year is not posted («zuerst
die MWST-Abrechnung buchen»). A period spanning two fiscal years belongs to the year it ends in.

**Lifting («Aufheben …»)** — admin only, the LATEST posted return only, a mandatory reason, ONE
return per action (no cascade: an earlier return is reached by lifting one after the other). Reached
through the ⋮ of the newest posted row or the red tool on the posted return — never the action
cell. The settlement entry is reversed on its own date (the pair stays in the journal), the period
is open again, and the next «MWST buchen» writes revision n+1 («Neuerstellung n»). The voucher of
the lifted return stays byte for byte. Refused while the fiscal year of the period's last day is
closed, and while a correction entry corrects the period. *When:* the return was posted by mistake —
not to change a return that was filed (that is [section 7](#7-errors-in-a-posted-period-the-correction-entry)).

## 5. Where each number comes from

```text
journal line (code, rate, base, tax)
  → tax code      module-vat master data, «MWST-Codes»
  → category      TaxCategory — the model's, not the installation's
  → form field    country pack res/country-packs/ch.json: category + rate → field (+ per-code exceptions)
  → VAT account   mandator «Konten», read through VatAccountMap
```

| Code | Category | Field | Account |
|---|---|---|---|
| UN / UR / US | standard / reduced / special | 303 / 313 / 343 (up to 2023: 302 / 312 / 342) | 2200 |
| UE | zero (export) | 220 | — |
| UL | zero, «Leistungen im Ausland» | 221 | — |
| UA | exempt | 230 | — |
| VM | input material | 400 | 1170 |
| VI | input other | 405 | 1171 |
| VE | «Einlageentsteuerung» | 410 (+) | 1171 |
| VK | «Vorsteuerkorrektur: gemischte Verwendung, Eigenverbrauch» | 415 (−) | 1171 |
| VN | «Vorsteuerkürzung: Subventionen, öffentliche Beiträge» | 420 (−) | 1171 |
| BM / BM2 | reverse charge (8.1 % / 2.6 %) | 383 + 400 | 2200 and 1170 |
| BI / BI2 | reverse charge (8.1 % / 2.6 %) | 383 + 405 | 2200 and 1171 |

- **The key is the tax code, not the account** — one revenue account holds several rates. A code an
  installation adds lands in the right field by its category; a category + rate the rule file does
  not map REFUSES the return, never a silent omission.
- **Field 200** is the turnover before reductions; **235** takes the reductions of the taxable
  categories by ORIGIN (credit notes, Skonto, losses), not by sign; a reversal pair nets out.
- **Rate fields and 383** are base × rate, kept exact; the payable amount is rounded ONCE. **Input
  fields** are the tax as POSTED (a voucher's tax may differ by a Rappen). The difference goes to 6945.
- **Reverse charge** is computed with full deduction (383 = its share of 400 / 405, payable effect
  zero) and warns once; a partial deduction is booked by hand with VK.
- **VE / VK / VN** are never computed on a document — only a Sammelbuchung posts them. A sole
  proprietor's private share goes through VK (415); staff and shareholders of an AG / GmbH are
  invoiced with VAT.
- **The three tabs:** «Formular» (the fields), «Herkunft» (field › origin › account › entries, adding
  up to the form to the Rappen), «Abstimmung» (revenue movements against 200 − 235, each VAT account
  against its coded tax — only the unexplained remainder is red; under each VAT account «Buchungen
  ohne passenden Code» lists the entries that do not fit).
- **A posted return stores** every field's figure, the resolved mapping and the rule file's hash —
  a code whose category changes later cannot alter it.

## 6. The lock of the VAT accounts and the Sammelbuchung

From «Abrechnung ab» to «Abrechnung bis», for a liable mandator, the three VAT accounts (2200, 1170,
1171 — read from the mandator) accept **only the tax z77 computes from the codes**: per entry, the
movement on each VAT account must equal Σ the tax of the coded lines that map to it. This holds for
manual entries AND every module (debtor, creditor, receipt, bank) — a module's posting is refused at
once, naming the document. Pickers leave the VAT accounts out; the server refuses them anyway; the
chart shows the badge «nur MWST» as soon as «Abrechnung ab» is set.

- **Exempt:** the settlement entry and its reversal, the year opening, a reversal through the
  ledger, a change of an old entry whose lines stay as they are (text and date may change), entries
  dated before «Abrechnung ab»; after «Abrechnung bis» the lock is off.
- **Not locked:** 2201, 6945, 1179, 2209. A Rappen difference against the ESTV's own figure is booked
  by hand from 2201 to 6945.
- **A coded line on a VAT account** is allowed only on the input-side account its code maps to — a
  «nur Steuer» line, VE / VK / VN, the reverse-charge pair line.

**The Sammelbuchung computes its tax lines.** The user types the net rows with their codes; z77 adds
one line per VAT account and side, text «MWST», shown read only in the block «MWST-Zeilen
(berechnet)» before the total. The narrow column «Steuer» takes the tax printed on a voucher when it
differs by a Rappen (within the one-line entry's tolerance); empty means computed.

*Example:*

```text
typed:     Dr 6500 Büromaterial 200.00  [VI]      Cr 1020 Bank 216.20
computed:  Dr 1171 Vorsteuer übriger BA  16.20  «MWST»
```

A row typed on 1171 without a code is a row error in the form. Any other writer that puts 20.00 there
is refused by the ledger: «Auf dem MWST-Konto «1171 …» stehen 20.00 im Soll, aus den MWST-Codes der
Buchung ergeben sich 16.20 im Soll — ein MWST-Konto nimmt nur die Steuer, die z77 aus den MWST-Codes
berechnet».

A row with a code ON a VAT account is a «nur Steuer» row (base 0.00, its amount is the tax): VM / VI
on their typed input account; VE / VK / VN may leave the account empty — z77 sets 1171. The three
corrections are offered in the Sammelbuchung only.

## 7. Errors in a posted period: the correction entry

z77 posts no correction return and no Jahresabstimmung. A **«Korrekturbuchung zu Periode X»** is a
manual entry **dated in the running period** that names the posted period it corrects: Finanzen ›
Journal › Sammel, the field «Korrigiert Periode» (the posted periods, newest first). Only an admin
sees the field; in the journal the entry carries the badge «Korrektur zu {Periode}».

- It needs at least one tax line; the named period must be posted and end before the entry's date;
  the entry is never dated INTO a posted period (the freeze has no exception); after «Abrechnung bis»
  it is refused.
- Its codes take the **rate of the corrected period** (its last day).
- The ordinary form of the running period leaves it out. The return shows it as the block
  **«Korrekturen früherer Perioden — separat im ePortal melden»**: per corrected period and field
  «ursprünglich» (the figure originally posted), «frühere Korrekturen» (only when the period was
  corrected before), the correction, and «neu» — Franken and Rappen with the exact value small under
  each tax amount, «neu» rounded once from the exact values.
- «MWST buchen» of the running period clears the block's tax onto 2201 with lines of its own
  («Korrektur {Periode}»), stores the block and prints it on the voucher. The user reports the block
  in the ePortal by hand; z77 keeps no filing state for it.
- A return is not lifted while a correction entry corrects its period — change or delete the entry
  first.

**Which way.** Measured against the calendar year of the RUNNING period:

- an error of the **same** calendar year — both ways are offered: an ordinary entry in the running
  period, or a correction entry; the user chooses per case;
- an error of an **earlier** calendar year — a correction entry, reported in the ePortal. z77 says
  so; it cannot prevent the ordinary entry.

*Example* — 2. Quartal 2026 is posted; in the running 3. Quartal an invoice of May turns out coded
UR instead of UN (revenue 1'000.00); the customer pays the missing tax. Correction entry
dated 12.08.2026, «Korrigiert Periode» 2. Quartal 2026:

```text
  Dr 3200 Ertrag 1'000.00  [UR]   → z77: Dr 2200   26.00
  Cr 3200 Ertrag 1'000.00  [UN]   → z77: Cr 2200   81.00
  Dr 1020 Bank         55.00      (no code — the tax the customer pays on top)
Block «2. Quartal 2026»:  303 +1'000.00 · tax +81.00   313 −1'000.00 · tax −26.00   399 +55.00   500 +55.00
«MWST buchen» 3. Quartal 2026 adds:  Dr 2200 55.00 / Cr 2201 55.00  «Korrektur 2. Quartal 2026»
```

The block lists only the fields WITH a correction, plus the tax total (399) and the payable amount
(500): field 200 nets to zero here (−1'000.00 + 1'000.00) and has no row.

## 8. The two date rules — kept apart on purpose

Two rules decide in which period tax falls when a document reaches into a posted period. They are
**two separate rules in two places** — never one shared date check.

**Input tax («Vorsteuer nachholen») — by the PAYMENT date** (the voucher date for a receipt; the
invoice date is only the rate date of a creditor invoice). Input tax claimed later is no error.

- Payment date in a posted period, running period in the **same** calendar year → caught up: the
  expense is posted at its date without a code and the tax on 1179; a second entry on the FIRST DAY
  of the running period moves it from 1179 to the input account. Automatic, shown as a grey line in
  «Journal verbuchen».
- Running period in **another** calendar year → refused: «Vorsteuer aus {Jahr} wird nicht nachgeholt
  — die Rechnung «ohne MWST» verbuchen und die Vorsteuer als Korrekturbuchung zu {Periode}
  erfassen.» The rest is by hand.

*Example* — 1. Quartal 2026 posted, a receipt of 15.03.2026 (108.10 with VM) is journalised in May:

```text
E1 15.03.2026  Dr 4400 Aufwand 100.00 / Dr 1179  8.10 / Cr 1000 Kasse 108.10   (no code)
E2 01.04.2026  Dr 1170   8.10 [VM, nur Steuer]  / Cr 1179  8.10   «… · Vorsteuer nachgeholt»
```

A receipt of 10.12.2025 with 4. Quartal 2025 posted and 1. Quartal 2026 running is refused instead.
A receipt with an OUTPUT code in a posted period is refused as well — only input tax is caught up.

**Reverse charge (Bezugsteuer) — by the INVOICE date** (the tax claim arises at receipt of the
invoice).

- Invoice date in an open period → on a **creditor invoice** the tax entry is dated AT the invoice
  date, as its own entry (Dr the input account [BM / BI] / Cr 2200), also before the invoice is
  paid — «Journal verbuchen» posts it and the invoice stays open; the expense follows at the
  payment date without a code (one entry when both dates are equal). A **receipt** is paid on the
  spot: always ONE entry at the receipt date. The amount of the document is the BASE (what was
  paid, without Swiss VAT); a foreign-currency invoice needs the «ESTV-Kurs», from which z77
  computes the base.
- Invoice date in a **posted** period → ALWAYS the correction path, also within the same calendar
  year; nothing is caught up in the running period. «Journal verbuchen» refuses: «Die Bezugsteuer
  gehört in die Periode {Periode}, sie ist abgerechnet — die Rechnung «ohne MWST» verbuchen und die
  Bezugsteuer als Korrekturbuchung zu {Periode} erfassen.» z77 does not check that the correction
  entry is made.
- «MWST buchen» is BLOCKED while an invoice with a reverse-charge code whose invoice date lies in
  the period being posted has no tax entry — it looks only at that period, so there is no deadlock.
- Before «Abrechnung ab»: «ohne MWST». After «Abrechnung bis»: no reverse-charge tax, only a hint
  that it may have to be declared outside z77.

*Example* — a foreign consultant's invoice of 20.05.2026, CHF 2'000.00, code BI, 2. Quartal 2026
already posted. The line is switched to «ohne MWST» and posted; then a correction entry «zu
2. Quartal 2026» in the running period:

```text
  Dr 6530 Beratung 2'000.00 [BI]   → z77: Dr 1171 162.00 / Cr 2200 162.00
  Cr 6530 Beratung 2'000.00        (no code — the expense nets to zero)
```

## 9. «nur Steuer» — import VAT

A «nur Steuer» line carries an input code (VM / VI) and **its amount is the tax** (stored with base
0.00 and the code's rate). On a receipt or a creditor line it is chosen in the VAT select; the debit
field locks («automatisch») and z77 takes the input account of the code — the server derives it, the
screen only shows it. The amount label is neutral, «Betrag» / «Betrag CHF». A creditor learns «nur
Steuer» together with the code.

*Example* — a customs assessment of import VAT 81.00, paid by bank: `Dr 1170 81.00 [VM, base 0.00]
/ Cr 1020 81.00`.

**The hint:** a creditor invoice is posted on its PAYMENT date. When its invoice date (the
assessment) and the payment date lie in different VAT periods, «Journal verbuchen» shows a grey line,
and «MWST buchen» of the payment period lists the warning again («Einfuhrsteuer der …:
Rechnungsdatum im {Periode A}, abgezogen im {Periode B} — prüfen.»), confirmed with the one tick. No
extra entry, no block.

## 10. The year end — account 2209

The case: an amount belongs to the old fiscal year, but its last VAT period is already posted (the
freeze refuses a tax line there). It is booked by hand with the ordinary rules, in two steps:

1. **On the last day of the fiscal year (usually 31.12.), without a tax code** — Dr cash / Cr revenue
   net / Cr 2209 the tax. The revenue stays in the old year; 2209 holds the open tax.
2. **In the running period, as a correction entry** naming the posted period — Cr revenue net WITH
   the code (z77 computes Cr 2200), Dr the same revenue net WITHOUT a code, Dr 2209 the tax.

*Example* — fiscal year = calendar year 2026, 4. Quartal 2026 posted; in March 2027 a cash sale of
December 2026 (1'081.00 incl. 8.1 %) surfaces:

```text
Step 1  31.12.2026  Dr 1000 Kasse 1'081.00 / Cr 3200 Ertrag 1'000.00 / Cr 2209 81.00   (no code)
Step 2  20.03.2027  correction entry «zu 4. Quartal 2026»
                    Cr 3200 Ertrag 1'000.00 [UN]  → z77: Cr 2200 81.00
                    Dr 3200 Ertrag 1'000.00       (no code)
                    Dr 2209          81.00
```

The input-tax mirror works the same way (step 1 Dr expense / Dr 2209; step 2 Dr expense with VM / VI,
Cr the same expense uncoded, Cr 2209). Step 1 makes «Abstimmung» of 4. Quartal 2026 say «Seit dem
Buchen geändert» — true, the books moved after filing.

**The close warning.** Closing the fiscal year warns — never blocks — while 2209 still holds an OPEN
amount (the balance at the year end netted with the later movements on it): «Konto 2209
MWST-Korrektur, noch nicht abgerechnet: Saldo {Betrag} — die Korrekturbuchung dazu steht noch aus.»
Confirmed with the tick «Warnungen gelesen», recorded in the close protocol. A step 2 already booked
silences it. 1179 is never used for this — it stays for input tax caught up.

## 11. The yearly overview — posts nothing

z77 files no Jahresabstimmung. The **«Jahresübersicht»** is a read-only overview per tax period —
the CALENDAR year: the corrections of the year per original period and field with the yearly sum,
the turnover reconciliation, input and owed tax against the declared figures, the balances of 2201,
1179 and 2209, the deadlines (day 180 / 240 only when the fiscal year is the calendar year, else
«Frist bei der ESTV klären»). It reads the STORED figures of the posted returns, never recomputes a
posted period, and blocks nothing.

- The tool «Jahresübersicht» on the list of the returns and the last line of the window «Jahr
  abschliessen» open the tax year whose 31.12. lies in the chosen fiscal year; three tabs
  «Korrekturen», «Umsatz», «Vorsteuer», and a PDF.
- It is «vorläufig» until every VAT period of the year is posted AND the fiscal year is closed; only
  then «Ablegen …» stores it as an unchangeable PDF in the archive with a protocol row
  («Jahresübersicht abgelegt»). Storing again is possible once it changed; every stored PDF stays.
- A fiscal year that differs from the calendar year is reconciled with the annual accounts by hand.

## 12. State of the build

Everything in this chapter is built (stages S1 to S7, 2026-11-25) and covered by the test group
`finance` — the screens of the correction entry, reverse charge on receipts and creditor invoices,
and the yearly overview with «Ablegen …» included. Two things are still open:

- **Nothing was seen in a browser yet.** The tests check the rendered pages and the text of the
  PDFs, not their look; the list of screens to walk through stands in `## pending` of
  [`financial.md`](../topics/financial.md), [`receipt.md`](../topics/receipt.md) and
  [`creditor.md`](../topics/creditor.md) («Owner's look — VAT return»).
- **Not deployed.** The VAT return goes onto a server only as a whole. An existing installation
  runs the migrations and names the accounts 2201, 6945, 1179 and 2209 on the mandator by hand —
  an existing chart of accounts gets no rows.

## 13. Deliberately not built — and for whom z77 is not suitable

Not built, by decision: the net tax rate and flat tax rate methods, received consideration, partial
input-tax deduction (full deduction assumed; cut by hand with VK), the electronic upload file
(eCH-0217), the option for exempt supplies, the notification procedure, margin taxation, the deferral
procedure for import tax, invoicing in a foreign currency, prepayments without a document, value vouchers, a correction return or
Jahresabstimmung posted by z77, a filed date, a cascade of lifts, a reconciliation by fiscal year, a
rhythm change other than on a 1 January, periods before 2024.

The first version is therefore NOT suitable for a business that settles by net tax rate, flat tax
rate or received consideration; needs partial deduction; uses the notification procedure, group
taxation, margin or travel-agent rules; imports under the deferral procedure; or needs any of the
items above. The full list with its sources: [`vat-return-review-2026-11-24.md`](../03-development/vat-return-review-2026-11-24.md)
sections 6.4 and 7.4.

## 14. Where to read further

- [`financial.md`](../topics/financial.md) — «P5 part 2»: the read path, «MWST buchen», lifting, the
  freeze, the lock, the Sammelbuchung, the correction entry and the block, the two date rules, 2209,
  the yearly read model.
- [`vat.md`](../topics/vat.md) — tax codes, rates, categories, the country pack and its form mapping.
- [`mandator.md`](../topics/mandator.md) — the VAT settings, «Rhythmus gilt ab», the accounts 1179 and 2209.
- [`receipt.md`](../topics/receipt.md), [`creditor.md`](../topics/creditor.md) — «nur Steuer»,
  «Vorsteuer nachholen», import VAT, reverse charge on documents.
- [ADR-041](../02-decisions/adr-041-vat-model.md) — the VAT model and the addenda of 2026-11-24 /
  2026-11-25 (method, mapping, reverse charge, tax-only line, catching up, calendar year).
- [ADR-042](../02-decisions/adr-042-ledger-and-money.md) — the ledger side: settlement entry, freeze,
  lifting, correction entry, lock, year end, yearly overview.
- [ADR-059](../02-decisions/adr-059-creditor-module.md) — the creditor's second entry;
  [ADR-061](../02-decisions/adr-061-bank-driven-bookkeeping.md) — no VAT account as the bank's contra
  account.
