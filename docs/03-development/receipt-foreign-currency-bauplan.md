# Build plan — foreign-currency receipts in the receipt journal

**Status:** `[CONCEPT]` — owner request and decisions 2026-11-12 (F1–F14); critical review
2026-11-12 (claims checked against the code and the live endpoint) worked in — §10 lists what it
changed. Open: the ESTV check of step 3 (§9). No code yet.
**Date:** 2026-11-12
**Builds on:** [`receipt.md`](../topics/receipt.md) (module-receipt as built, owner decisions
E1–E16), [ADR-042](../02-decisions/adr-042-ledger-and-money.md) decision 4 (the ledger holds the
base currency only; a DOCUMENT may carry a foreign amount and its rate — this plan stays inside it,
no new ADR), [`gateway-app-kanal-bauplan.md`](gateway-app-kanal-bauplan.md) (E5 «amount required»
is amended for foreign receipts by F3).

---

## 1. Why

A Bauhaus receipt over 52.95 EUR, paid by card. The card bills an exact CHF amount (rate + fee)
that only the statement shows. Booking such a receipt at a fixed rate leaves the credit-card
account off every statement. Cash paid in EUR has no statement — there a fixed rate is right.
Today the phone has only «Betrag CHF» (required, E5), so the EUR receipt either waits outside the
journal or is booked at a guessed rate.

## 2. Owner decisions (2026-11-12)

- **F1 — Card: the CHF amount from the statement. Cash in EUR: a fixed rate** (the ESTV monthly
  average) is fine; the EUR amount and the rate go into the posting text. The journal is posted at
  month end, so waiting for the statement costs nothing.
- **F2 — A receipt carries an optional original amount and currency** (ISO 4217, e.g. `EUR`); the
  posting stays in the base currency CHF. A migration adds the columns.
- **F3 — Capture (phone and backend): with a foreign currency the CHF amount may stay EMPTY.**
  Amends E5 for foreign receipts only; a CHF receipt keeps the amount required.
- **F4 — A foreign receipt without CHF amount is INCOMPLETE:** «Journal verbuchen» is blocked as for
  any incomplete receipt, the preview says why; the CHF amount is filled in in the Belegjournal.
- **F5 — The posting text carries the original amount**, e.g. «Bauhaus · 52.95 EUR», plus the
  rate when one was used (narrowed by F12).
- **F6 — A rate suggestion computes CHF from the original amount** — always an editable
  suggestion, never forced.
- **F7 — Currencies: a list in config** (Rule 2, one semantically named place:
  `receiptConfig['foreignCurrencies']`), default only `EUR`, extendable. Phone: a CHF/EUR toggle,
  a small select when the list has more than one entry — no long list.
- **F8 — Rate source: the ESTV monthly average rate (MWST Monatsmittelkurs)**, published by BAZG
  as XML at `https://www.backend-rates.bazg.admin.ch/api/xmlavgmonth`, namespace
  `https://www.backend-rates.bazg.admin.ch/avgratesmonth`, entries like `<devise code="eur">
  <waehrung>1 EUR</waehrung><kurs>0.9428</kurs></devise>`. Fetched server-side on first need,
  stored locally, NEVER from the client (Rule 7: no foreign origin from JS, CSP). No stored rate =
  no suggestion, manual rate. The external call is visible: documented, short timeout, failure = no
  suggestion + a log line — not a hidden dependency.
- **F9 — Not per payment method.** Always the same behaviour: with a foreign currency the CHF
  amount may stay empty, the rate calculator is offered in the Belegjournal window for every
  foreign receipt. Card: enter CHF later from the statement. Cash: enter or accept a rate → CHF
  computed.
- **F10 — VAT stays open on a foreign receipt** (answer to Q2): at capture the template's DEBIT
  account is taken, its VAT decision is NOT — the receipt shows «MWST — fehlt» until the owner
  picks a code or «ohne MWST» in the journal.
- **F11 — A foreign receipt is never split** (answer to Q3).
- **F12 — CHF is FREE** (answer to Q4): it may differ from original × rate (a card statement
  includes the fee). The rate is only a suggestion. When CHF ≠ original × rate, the rate is NOT
  stored and NOT in the posting text — only «52.95 EUR».
- **F13 — The rate fetcher lives in module-receipt** (answer to Q5).
- **F14 — No monthly fetch job** (Q1 superseded, **confirmed by the owner 2026-11-12**). The owner
  said yes to a job on the premise «the endpoint serves only the current month». The review showed
  the premise wrong: `xmlavgmonth?j=2026&m=10` answers `<monat>2026-10</monat>` (EUR 0.9507),
  `j=2025&m=3` answers March 2025 (EUR 0.9525). Each receipt's month can be fetched when it is
  needed, so the job has no case left.

## 3. Technical proposals

**T1 — Columns** (table `receipt`, new migration — §6):

| column | type | meaning |
|---|---|---|
| `amount` | `NUMERIC(15,2)` **NULL** (was NOT NULL) | the CHF gross; null only on a foreign receipt not completed yet |
| `original_amount` | `NUMERIC(15,2)` NULL | the amount on the slip, in `original_currency` |
| `original_currency` | `VARCHAR(3)` NULL | ISO 4217 upper case; null = a CHF receipt |
| `exchange_rate` | `NUMERIC(12,6)` NULL | CHF per ONE unit; set only when CHF = original × rate (F12) |

`amount` stays `MoneyType` (base currency, ADR-042). `original_amount` is NOT a `MoneyType`
column — that type refuses any currency but the base one by design — so it is mapped as
`Types::DECIMAL` (a string, never a float) and the getter builds `Money::fromDecimal($value,
$currency)`. `VARCHAR(3)` and `NUMERIC(12,6)` are debtor's `invoice.currency` /
`invoice.exchange_rate` — one convention for «a document in a foreign currency». `Money` has a
fixed scale of 2, so a currency with 0 or 3 minor places is refused at config read (T3).

**T2 — Entity invariants** (`Receipt`, one private `setAmounts(?Money $chf, ?Money $original,
?string $rate)` used by the constructor and `correct()`): original amount and currency both set or
both null; the currency is never the base currency; `amount` null ONLY when an original is set;
`exchange_rate` only with an original; every amount and the rate positive. `getAmount(): ?Money`.
`splitOff()` keeps its code and refuses a foreign receipt with a `LogicException` (defence behind
T6 — it never needs `setAmounts()`). `missing()` lists **«Betrag CHF»** FIRST when `amount` is null
(label from `ReceiptFields::currency()`). The sentence «es fehlt: …» is built in ONE place,
`ReceiptPostingBuilder::missingText(Receipt)`, used by `build()`, `post()` and the band's blockers
(today three places implode `missing()` themselves); for a missing CHF amount: «es fehlt: Betrag
CHF (Beleg über 52.95 EUR — CHF von der Kartenabrechnung oder mit Kurs eintragen)».

**T3 — `ReceiptFields`** (the one rule set, receipt.md rule):
- `foreignCurrencies(): list<string>` — the ONE reader of `receiptConfig['foreignCurrencies']`;
  each entry `^[A-Z]{3}$`, not the base currency, not on a deny-list of currencies without two
  minor places (`ReceiptFields::NOT_TWO_DECIMALS`: JPY, KRW, ISK, CLP, VND, PYG, UGX, XOF, XAF,
  BHD, KWD, OMR, JOD, TND, LYD, IQD) — else a `LogicException` (a config error is loud).
- `foreignCurrency($value, ?string $kept = null)` — '' or the base currency → null (a CHF
  receipt); else upper-cased and in the list OR equal to `$kept` (the receipt's own currency —
  `update()` passes it, so a currency removed from the config later still saves); else «Währung
  XYZ ist nicht freigegeben (erlaubt: EUR).». (Not `currency()` — that name answers the base
  currency.)
- `originalAmount($value, $currency)` — required with a foreign currency, parsed with
  `ManualEntryForm::parseAmount($value, $currency)`, positive; given without a currency →
  «Betrag in Fremdwährung ohne Währung.».
- `amount($value, …, required: bool)` — required for a CHF receipt (E5 unchanged), optional with a
  foreign currency (F3).
- `exchangeRate($value)` — '' → null; comma normalised to a point, a decimal with at most 6 places,
  > 0, at most `999999.999999`; else «Kurs: eine Zahl grösser 0, höchstens 6 Nachkommastellen
  (z.B. 0.9428).». Answers the normalised string, trailing zeros trimmed (the database gives back
  `0.942800`). Given without a foreign currency → refused.
- `chfFromRate(Money $original, string $rate): Money` — `$original->multiply($rate)` on the
  NORMALISED rate (`Money::multiply()` takes only `\d+(\.\d+)?`), exact, rounded half away from
  zero to the Rappen, re-labelled to the base currency. The ONE place CHF is computed. Comparisons
  are `Money::equals()`, never string compares.

**T4 — Capture and the API contract.** `ReceiptCaptureService::capture()` gains two trailing
named parameters `?string $originalAmount = null, ?string $currency = null` (existing callers
untouched); with a foreign currency it applies the template's debit only (F10).
`POST /api/v1/receipts` gains the optional form fields `original_amount` and `currency`; `amount`
becomes optional when `currency` names a foreign currency (`ReceiptsService::FIELDS` extended). A
request without `currency` (an entry queued before the update) is a CHF capture as today. The
phone sends no rate. The JSON answer adds `original_amount` and `currency` (null for a CHF receipt)
and `amount` may be `null`: `{"receipt": {"id": 18, "date": "2026-11-12", "amount": null,
"original_amount": "52.95", "currency": "EUR", …, "state": "captured"}, "message": "Beleg
erfasst"}`. Errors stay 422 `invalid_fields` with the new keys `original_amount` / `currency`.

**T5 — Correction and the rate rule** (F12). `ReceiptCorrection` gains `currency`,
`originalAmount`, `exchangeRate` (strings, '' = none); `ReceiptJournalService::update()` checks
them with T3 (passing the receipt's own currency as `$kept`). One server rule:
- rate given, CHF empty → CHF = `chfFromRate()`, rate stored;
- rate given, CHF given and equal to `chfFromRate()` → rate stored;
- rate given, CHF given and different → CHF stored as typed, rate NOT stored; the save message
  says so: «Kurs nicht übernommen — der CHF-Betrag weicht davon ab (z.B. Kartenabrechnung mit
  Gebühr). Im Buchungstext steht nur 52.95 EUR.»;
- rate empty → CHF as typed (the card case), `exchange_rate` null;
- currency set back to CHF → original amount and rate must be empty (refused otherwise).

**T6 — Split on a foreign receipt: refused** (F11). `split()` answers «Ein Beleg in Fremdwährung
wird nicht aufgeteilt.» and the window offers no «Aufteilen …» for it. This is the owner's call for
simplicity, not a VAT argument: a Swiss supplier may well invoice in EUR with Swiss VAT. That case
is covered by F10 — the owner picks the code, and the help (`edit.help`, section `tax_code`) says:
the input tax of such a receipt is computed from the CHF gross.

**T7 — Posting text** (`ReceiptPostingBuilder`, the one place; the receipt's own `text` stays the
owner's): `<text> · 52.95 EUR` without a stored rate, `<text> · 52.95 EUR · Kurs 0.9428` with one.
The amount as `AmountFormat::of()` writes it (`1'052.95`), the code upper case, the rate trimmed to
at least 4 places (`0.9428`, a 100-unit currency `0.017023`). The receipt text is cut so the whole
fits `JournalEntry::TEXT_LENGTH` — the suffix is never cut.

**T8 — Every place that assumed a non-null amount:**
- `ReceiptJournalPostingService::preview()` — a receipt without CHF is blocked by `build()`
  (INCOMPLETE) before any sum; `blockedGross` adds only a known amount, the preview gains
  `blockedWithoutAmount` (count). `post()` sums only complete receipts — non-null.
- the band (`receiptTotals()`): total over known CHF plus «· n ohne CHF-Betrag».
- the list (`ReceiptJournalListing` / `listAction.tpl.php`): «Betrag» = CHF or «—», under it muted
  «52.95 EUR»; the state column says «CHF fehlt» ahead of «Konten fehlen». Sort by amount puts empty
  CHF first (MySQL NULL order) — accepted.
- search «Betrag» (`ReceiptRepository::search()`): `(amount = ? OR original_amount = ?)` — the
  `$add` helper binds ONE value, so this condition is written like the «Erfasst von» one (two
  params, two types pushed).
- `ReceiptRepository::unpostedBetween()` — `SUM(amount)` ignores NULL; it also returns the count
  without CHF, and `UnpostedReceiptsCheck` (ledger-report warning) says «Total … (n ohne
  CHF-Betrag)». The blocking findings are unchanged.
- templates: `confirmDelete.tpl.php:41` and `split.tpl.php` show «—» + «52.95 EUR» for a null
  amount (`AmountFormat::of(null)` answers ''); `edit.tpl.php:119` drops `required` on the CHF
  field for a foreign receipt.
- `receiptValues()` / `receiptPosted()`, the split message, `ReceiptsService` — read `?Money`.

**T9 — ESTV monthly rates** (`Services\EstvMonthlyRates`, module-receipt, F13):
- **Storage: `var/lib/receipt/estv-rates/<YYYY-MM>.json`.** It is a cache: every month can be
  fetched again (F14), and the rate actually used lives on the receipt (`exchange_rate`). That
  passes ADR-034's test «may it be deleted at any moment without losing information?»; `var/lib`
  is shared across releases (ADR-035), so a release switch does not refetch. Content: `{month,
  fetched_at, rates: {"EUR": {"unit": 1, "kurs": "0.9428"}, "EGP": {"unit": 100, "kurs":
  "1.7023"}, …}}` — all currencies of the answer as published.
- **The month that matters is the RECEIPT DATE's month** (the ESTV applies a monthly average to the
  whole month). The request names it: `?j=<YYYY>&m=<M>`. The answer counts only when its `<monat>`
  equals the requested month; anything else = no rate, nothing stored. A month AFTER the current
  one is never requested (a receipt is never dated in the future, `ReceiptFields::date()`). An
  answer without any `<devise>` = no rate, not stored.
- **Lifetime:** a PAST month is stored for good; the CURRENT month only for 24 hours, then fetched
  again — the review saw `j=2026&m=12` already answered on 2026-11-12, so a current-month figure
  may be provisional (§8, and the mandatory check of step 3).
- **When:** on first need — the receipt window of a foreign receipt is rendered and the receipt
  month's file is missing or expired. Never at capture (the phone's POST stays fast and independent
  of BAZG).
- **The call:** URL a class constant `EstvMonthlyRates::SOURCE_URL` (the `GeoIpUpdateJob`
  pattern, not configurable); `curl` with `CURLOPT_PROTOCOLS = CURLPROTO_HTTPS`, no
  `FOLLOWLOCATION`, certificate verified, connect timeout 2 s, total 4 s, at most 256 KiB enforced
  in a `CURLOPT_WRITEFUNCTION` that aborts beyond it. The `j`/`m` parameters are not documented by
  BAZG: an answer that ignores them fails the `<monat>` check → no suggestion.
- **Parsing** as `CamtReader::read()` does (`simplexml_load_string(…, LIBXML_NONET)`, internal
  errors, no `LIBXML_NOENT` / `LIBXML_DTDLOAD`), and an answer containing `<!DOCTYPE` is refused
  before parsing. Strict: the namespace above, `<monat>` `YYYY-MM`, each `devise@code` `[a-z]{3}`,
  `<waehrung>` `^1(0{0,4}) [A-Z]{3}$` (units 1, 10, 100, 1000, 10000 — live today: 1, 100, 1000,
  10000), the code equal to `devise@code` upper-cased, `<kurs>` a positive decimal. Anything else
  refuses the whole answer (no half table).
- **Per unit:** `rate(currency, month)` answers CHF per ONE unit = `kurs / unit` — ONLY when that
  quotient has at most 6 decimal places exactly (fits `NUMERIC(12,6)`, debtor parity); otherwise no
  suggestion for that currency (a 10000-unit currency with a 4-place `kurs` needs 8 places).
- **Failure** (network, HTTP status, size, parse, month mismatch) = no suggestion,
  `error_log('receipt estv-rates <YYYY-MM>: …')`, and one try per 30 minutes per month through
  `FileThrottle` under `var/lib/throttle/receipt-estv`, key `estv:<YYYY-MM>` (an outage must not
  make every window open wait 4 s; another month is not held back).
- **Config — only a switch:** `receiptConfig['estvRateSuggestion'] => true`; `false` = never
  fetch, manual rates only. The config comment names the external host and the SIX note (§8).

**T10 — The backend window** (board first, Rule 8): «Vom Handy» becomes «Beleg», one row
**Währung** (select: CHF + the configured list + the receipt's own currency) · **Betrag (Währung)**
· **Kurs** · **Betrag CHF (inkl. MWST)**. CHF receipt: only CHF matters. Foreign receipt: under
«Kurs» the suggestion as text — «ESTV 11/2026: 1 EUR = 0.9428 CHF» (as published for 100-unit
currencies) — and a plain submit button **«ESTV-Kurs übernehmen»** (`name="apply_rate"`): the POST
re-renders the window with Kurs = the suggestion and CHF = `chfFromRate()`, NOTHING saved — the
owner checks and presses «Speichern». No stored rate → «Kein ESTV-Kurs für 10/2026 — Kurs von Hand
oder CHF von der Abrechnung.». The rate field is never pre-filled on GET (a pre-filled rate would
turn a card receipt into a fixed-rate one — F1). A typed rate with an empty CHF is computed by the
save (T5). No script, no core.js change. Help (`edit.help`): sections `currency`,
`original_amount`, `exchange_rate` (card vs cash, F1, F12) and the input-tax sentence of T6.

**T11 — The phone screen** (`App/receipt.tpl.php` + `receipt.js`; board first, Rule 8): the
currency control is rendered by the SERVER from `foreignCurrencies` (no new endpoint): one entry →
chips «CHF | EUR» beside the amount (the `.za-chips` of «Bezahlt mit»), more → a small select. The
amount label follows («Betrag EUR»); with a foreign currency a muted line «CHF trägst du im Journal
nach — Kartenabrechnung oder Kurs.». Sends `currency` + `original_amount` (no `amount`); CHF sends
`amount` as today. Each new receipt starts at CHF (a remembered EUR would mislabel the next CHF
slip). Queue entries carry the two new fields; reopening a refused entry (`receipt.js:348`)
restores currency and amount field; the done view and «Heute gesendet» (`:287`, `:310`) show
«52.95 EUR» when `receipt.amount` is null. DOM + `textContent` only. Versioned asset names — no
service worker cache bump.

**T12 — Rounding:** CHF to 0.01 (card statements are to the Rappen; no 0.05 rounding on a
receipt), half away from zero (`Money::multiply()`); the rate as published per unit (T9) or typed
(up to 6 places); original amounts with 2 places (`Money` scale, T3 deny-list).

## 4. Steps

0. **Owner:** F14 confirmed 2026-11-12 (no job).
1. **Boards on the developer canvas (Rule 8):** the receipt window with the amount row, the
   suggestion and «ESTV-Kurs übernehmen»; the phone capture with the CHF/EUR toggle; the list row
   and band with «CHF fehlt». The owner looks before anything is built.
2. **Domain:** migration, entity (T1, T2), `ReceiptFields` (T3), capture (T4 service part, F10),
   correction and rate rule (T5), split refusal (T6), posting text (T7), the null-safe sums and
   search (T8), `missingText()`.
3. **ESTV rates (T9):** `EstvMonthlyRates` (fetch, parse, store, lookup, lifetime, throttle),
   config switch. **Mandatory before it is wired to the window:** compare the endpoint's EUR, USD,
   GBP and one 100-unit rate for the current and the previous month against the official ESTV PDF
   of the same months, and record the result (month semantics, whether the current month's figure
   is provisional) in `receipt.md`. A mismatch stops the step for an owner decision.
4. **Backend screen (T8 list/band/templates, T10):** window fields, `apply_rate`, list and band,
   help sections.
5. **API + phone (T4, T11):** `ReceiptsService`, template, `receipt.js` + `npm run build:js` +
   `php tests/js-min-markers.php`.
6. **Docs:** `receipt.md` (mental model: the foreign receipt, F1–F14; rules: CHF computed only in
   `ReceiptFields::chfFromRate()`, rates only through `EstvMonthlyRates`, never a client fetch;
   known issues: §8 residuals; file map: new sources and `RUNTIME=/skeleton/var/lib/receipt/estv-rates`),
   `api.md` if it lists the receipt fields; `npm run docs:check`.

Rough effort: 2 ≈ 1.5 days, 3 ≈ 1 day, 4 ≈ 1 day, 5 ≈ 1 day, docs and tests ≈ 0.5 day.

## 5. Tests (in `tests/module-receipt.php`, its O / S / M / SM sections)

- **Step 2 — O:** entity invariants (original without currency, base currency as original,
  null CHF without original, rate without original — each refused; `splitOff()` on a foreign
  receipt refused); `missing()` puts «Betrag CHF» first; `chfFromRate()` (52.95 × 0.9428 = 49.92;
  half away from zero on a .5 Rappen; «0,9428» and «0.942800» give the same CHF); `ReceiptFields`
  per rule incl. the config check (lower case, base currency, JPY, 4 letters → LogicException) and
  the kept currency; posting text with and without rate, a 255-character text cut, the suffix
  intact. **M:** migration up/down; capture EUR without CHF → incomplete, template debit taken,
  VAT open (F10); «Betrag CHF» blocks `preview()` with the missing text, `post()` refuses
  INCOMPLETE; CHF without amount still refused (E5); correction: rate → CHF filled and rate stored,
  rate + equal CHF stored, rate + different CHF → CHF kept and rate null, CHF alone kept, back to
  CHF clears, a currency removed from the config still saves on its receipt; split of a foreign
  receipt refused; a complete EUR receipt posts CHF lines with the suffixed text; search by the
  slip amount finds it; `unpostedBetween()` counts receipts without CHF.
- **Step 3 — O (offline, fixture XML, transport injectable, no network):** units 1, 100, 1000,
  10000 parsed; a per-unit rate with more than 6 places → no suggestion; `<monat>` ≠ requested →
  nothing stored; no `<devise>` → nothing stored; `<!DOCTYPE`, a wrong namespace, an HTML error
  page, a negative `kurs`, a 257 KiB answer → refused whole; a future month never requested; a past
  month kept, the current month refetched after 24 h; the throttle per month (October's failure does
  not hold back September); `estvRateSuggestion => false` never calls the transport.
- **Step 4 — S/SM:** the window renders the four fields, the suggestion or «Kein ESTV-Kurs …», no
  «Aufteilen …» on a foreign receipt, no `required` on its CHF field; `apply_rate` re-renders with
  rate and CHF and saves nothing; the save message of a dropped rate; the list «—» + «52.95 EUR» and
  «CHF fehlt»; the band «n ohne CHF-Betrag»; «Löschen …» of a foreign receipt without CHF renders.
- **Step 5 — O/SM:** `POST receipts` with `currency=EUR&original_amount=52.95`, no amount → 200
  with `amount: null`; without `currency` → a CHF capture as before; unknown currency → 422
  `currency`; CHF without amount → 422 `amount`; `FIELDS` holds the two keys; the template renders
  chips for one currency, a select for two; `php tests/js-min-markers.php` green.

## 6. Deploy notes

- **Migration** `Z77\Module\Receipt\Migrations\Version20261112160000` (module-receipt):
  `ALTER TABLE receipt MODIFY amount NUMERIC(15,2) DEFAULT NULL, ADD original_amount
  NUMERIC(15,2) DEFAULT NULL, ADD original_currency VARCHAR(3) DEFAULT NULL, ADD exchange_rate
  NUMERIC(12,6) DEFAULT NULL`; `down()` refuses while a row has `amount IS NULL`. A NEW migration,
  not an edit of `Version20261112100000` — it may already have run somewhere.
- **Config:** `foreignCurrencies` (`['EUR']`) and `estvRateSuggestion` (`true`) are new keys in
  `receiptConfig.inc.php`; a project overriding that file must add them (BOOT-CONFIG-001 — the
  override replaces the whole file).
- **Server:** outbound HTTPS to `www.backend-rates.bazg.admin.ch:443` allowed, ext-curl present;
  `var/lib/receipt/estv-rates/` is created on the first fetch (`var/lib` is the shared branch,
  ADR-035). No cron job.
- **Assets:** `receipt.min.js` rebuilt. **No navigation change.**
- **Order:** migrate and switch together — the old release cannot hydrate a receipt with
  `amount` NULL (non-nullable `Money` property); no foreign capture before the switch.

## 7. Not in scope

A rate on the phone; a rate per payment method (F9); revaluation or any currency in the ledger
(ADR-042 decision 4); currencies without two minor places (`Money` scale 2); a scheduled rate fetch
(F14).

## 8. Risks

- **SIX copyright.** The BAZG page carries «Weiterverbreitung und Nutzung durch Dritte untersagt»
  for the SIX-sourced rates. The ESTV recommends the link for accounting software; we use the rate
  inside the installation's own books, cache it per installation and redistribute nothing (no
  API exposes the table, no export). If the owner reads the note stricter:
  `estvRateSuggestion => false`, manual rates remain.
- **Undocumented parameters** `j` / `m`: BAZG may drop or change them. The `<monat>` check turns
  that into «no suggestion» plus a log line — never a wrong month.
- **`<monat>` semantics / provisional figures:** on 2026-11-12 the endpoint already answered
  December 2026 (EUR 0.9292), which does not fit «published around the 25th, applies to the next
  month». Mitigated by never asking a future month, the 24-hour lifetime of the current month and
  the mandatory PDF check of step 3. A rate once taken is the receipt's, whatever the cache does
  later.
- **Endpoint change** (URL, format, namespace): the strict parser refuses, the log says so, the
  owner types the rate — nothing posts wrong.
- **Release window:** old code on a NULL amount (§6 order).

## 9. Open

- **F14 — owner's confirmation** that the monthly job is dropped now that past months can be
  fetched.
- **Step 3's ESTV check** (month semantics, provisional current month) — its result may change the
  24-hour rule of T9.

## 10. What the review changed (2026-11-12)

Blocking findings taken over: the endpoint serves past months (`j`/`m`) → fetch the receipt month
explicitly, require `<monat>` = requested, job and «lost months» dropped (F14); units up to 10000;
a suggestion only when `kurs / unit` fits 6 places; storage `var/lib` (a re-fetchable cache, the
used rate on the receipt) instead of `data/`; the `<monat>` contradiction → no future month, no
empty answer stored, a short lifetime for the current month, a mandatory PDF check. Others taken
over: the delete confirmation and the CHF field's `required`; the receipt's own currency accepted
by `update()`; the URL a constant, config only a switch, HTTPS-only curl with a write-callback size
limit; XML parsed as `CamtReader` does and `<!DOCTYPE` refused; the throttle per month; a deny-list
for currencies without two places; the split reasoning corrected (a Swiss supplier may bill in EUR
with Swiss VAT); the two-value search condition; the phone's reopened queue entry and null amount;
«ESTV-Kurs übernehmen» a plain submit, no core.js change; the rate normalised before
`Money::multiply()`; `splitOff()` without `setAmounts()`. Owner answers Q2–Q5 recorded as F10–F13.

## 11. Boards (step 1) — drawn 2026-11-12, owner's look open

On the developer canvas (version 31, row «Belege in Fremdwährung — Entwurf 2026-11-12»): the receipt
window (state A card, CHF empty; state B cash, rate applied, posting-text preview), the journal row +
band + «Journal verbuchen» window with «CHF fehlt», the phone capture with the CHF | EUR toggle.
Not yet looked at rendered. Open board questions (variant 1 drawn each time):

1. The rate field — in the amount row or a row of its own below it?
2. The ESTV hint — «Monatsmittel 10/2026: 0.9507» or «1 EUR = 0.9507 CHF» (T10)?
3. The phone hint text — the owner's wording or T11's (the phone has no CHF field to leave empty)?
4. A CHF receipt — foreign amount and rate hidden or disabled?
5. The posting-text preview in the window — wanted (not in this plan yet)?
