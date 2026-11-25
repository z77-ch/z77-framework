# Changelog

All notable, user-visible changes to the z77 framework and its packages are recorded here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/); versions follow
[Semantic Versioning](https://semver.org/).

This file starts on 2026-11-15. Earlier history lives in the git log and in the topic docs
([`docs/topics/`](docs/topics/) — each area's decisions, resolved issues and dates).

**How entries are added:** one line per user-visible change, written in the SAME commit as the
change, under `## [Unreleased]` in the matching group (Added / Changed / Fixed / Removed /
Docs). Name the package or area first. No commit hashes, no client names. At a release the
`[Unreleased]` block becomes `## [x.y.z] — YYYY-MM-DD`.

## [Unreleased]

### Added

- **kernel/shared + module-backend + module-financial:** menu conditions — a module may hide a
  backend menu entry by DATA, not only by access (owner 2026-11-24). It registers a
  `MenuCondition` (`visible(): bool`) for the entry's route under the config key `menuConditions`
  (a project: `App/Config/menuConditionsConfig.inc.php`); `BackendMenu` asks it after
  `canReach()`. No condition = shown as before; a condition that fails is logged and the entry
  shown. First user: Finanzen › «MWST-Abrechnungen» is no longer in the menu (rail, topbar,
  dashboard tile) for a mandator that is not liable to VAT and has no «Abrechnung bis»; the page
  itself still answers a typed URL with its message, Webseiten › Navigation still lists the record.

- **kernel/shared + module-backend:** a dashboard tile may show figures — stock numbers that are
  not open work, «12 Mandanten · 2 ruhend» (owner 2026-11-22, ADR-052 addendum, DASH-FIGURE-001).
  A contributor yields `DashboardEntry::figure(label, count, route, query = [], link = true)`;
  placed by route and filtered by `canReach()` like every entry, never in the pending list, never
  a badge. All figures of a tile stand in ONE quiet sub line under its head (`.be-tile__figures`,
  linked or plain text, never wraps). No stock module delivers one — without a contributor
  nothing changes. **Projects:** run `composer install` so `public/assets/backend` gets the new
  `base.css`.

- **module-mandator:** the settings of the VAT return on the tab «UID und MWST» —
  Abrechnungsmethode, Abrechnungsart, Abrechnungsrhythmus (Quartal / Monat / Jahr), Abrechnung ab /
  bis, shown while «Mehrwertsteuerpflichtig» is on — and the accounts «Abrechnungskonto MWST»
  (2201) and «Rundungsdifferenzen MWST» (6945) on «Konten»; `CurrentMandator::vatSettings()`. The
  setup step asks a LIABLE mandator for what is missing. KMU chart: 6945 «Rundungsdifferenzen
  MWST» — an existing installation creates the account by hand. Migration
  `Version20261124120000` (owner 2026-11-24, ADR-041 addendum points 1–3, 13). **Projects:** run
  `php vendor/bin/z77-db migrate`.
- **module-financial:** the tables of the POSTED VAT return — `vat_return` (one row per posted
  return: period, settings, form edition and resolved mapping, the declared payable amount, the
  settlement entry or none for a nil return, «Eingereicht am», the lift, the archived voucher),
  `vat_return_figure` (the declared figure of every form field, exact) and `vat_return_log` (the
  protocol) — and `VatReturn\VatSettlement`, the one reader of «settled»: a period is settled
  while a posted ordinary return covers it (ADR-042 addendum 2026-11-24 point 2; stage S2 slice 1).
  Nothing writes a return yet — every period stays «offen», nothing visible changes. Migration
  `Version20261124182954`. **Projects:** run `php vendor/bin/z77-db migrate`.
- **module-vat + module-financial:** the calculation core of the VAT return, without a screen
  yet (stage S1 of [`vat-return-review-2026-11-24.md`](docs/03-development/vat-return-review-2026-11-24.md)).
  Country pack CH — the form mapping as a readable rule file (`res/country-packs/ch.json`:
  category + rate → form field per form edition, exceptions per code, an unmapped code
  refuses); `VatReturnCalculator` computes the form from aggregated coded lines (field 235 by
  origin, tax = base × rate kept exact, the payable amount rounded once, input tax as posted, the
  plausibility checks of the electronic return); `VatPeriodCalendar` — quarter, month, year, a
  short first period. New harness `financial-vat-return`. No behaviour changes.
  Stage S1a adds the read-only data layer, still without a screen: `VatReturnService` — the
  settings gate (not liable, method / consideration not effective + agreed, rhythm or «Abrechnung
  ab» not set — each refusal with a German sentence), the periods ending in a fiscal year with
  their payable amount, «Nächste Abrechnung», one period's return (every field with its label and
  whether it is shown, the tree field › origin › account), the entries of a field as a list query,
  the reconciliation of turnover and VAT accounts by movements, and the findings (open work, a gap
  on a VAT account, uncoded revenue, uncoded credits on fixed assets, negative fields, exempt
  turnover, rounding). Credit notes, discounts and losses reduce the turnover by their origin: debtor,
  receipt, creditor and financial declare their journal source types under the new config key
  `vatReturnSources`. Refuses a period with reverse-charge lines (until that stage) or an unmapped
  code. New harness `financial-vat-return-ledger`. Nothing is written.
  Stage S1b makes the return VISIBLE, still read-only (nothing is posted): the new menu entry
  Finanzen › «MWST-Abrechnungen» lists the periods ending in the chosen fiscal year with state and
  payable amount, «Nächste Abrechnung» in the action cell; one return opens in three tabs —
  «Formular» (open points with links, the form with only the fields that carry an amount, the
  posted tax and its difference at the total owed and the payable amount, «Deklaration netto (ohne
  MWST)»), «Herkunft» (field › origin › account, the entries of each node as a searchable list) and
  «Abstimmung» (revenue and VAT accounts against the form, the unexplained remainder in red) — and
  as a PDF with the exact tax per rate field; help for each screen, including what z77 does not
  settle. «MWST buchen», corrections, reverse charge and the column «Ins ePortal» follow. A credit
  without a tax code on a value-adjustment account («Wertberichtigungen …») no longer warns. The
  backend list gains tree rows in its columns (`.be-list__table--tree`). Projects: run `composer
  install` (the new backend CSS reaches `public/`); the menu entry is added by the installer's seed
  step at the end of Finanzen — move it in Webseiten › Navigation if wanted.

### Changed

- **module-financial (+ module-creditor, module-receipt):** «VAT-settled» is no longer a period
  state — a date is settled while a posted VAT return covers it (ADR-042 addendum 2026-11-24).
  The ledger refuses a posting, an amendment, a retraction, a manual edit or delete and a
  reversal that carries a tax line dated in a settled VAT period (`vat-period-settled`; a line
  without a tax code stays possible while the year is open), on a locking read after the period
  lock. New: `LedgerService::periodRefusal(date, withTaxLine)` — the answer a module asks before
  it posts — and `periodRefusalText()`, the one German sentence; creditor, receipt, the bank and
  the journal read them. Nothing visible changes yet: no return can be posted, so no period is
  settled. **Projects:** code that names `PeriodState::VatSettled`, `PERIOD_VAT_SETTLED` or the
  reason `period-vat-settled` must switch to `VAT_PERIOD_SETTLED` / `vat-period-settled`;
  `PostingRules::assertPeriodAccepts()` / `assertPeriodAllowsChange()` lost their
  `$withTaxLine` parameter (internal). No migration.

- **module-debtor + module-financial (+ module-creditor, module-receipt):** a settled VAT period in
  the other modules (VAT return stage S2 slice 3). The refusal names the VAT period by its label
  («Die MWST-Periode 3. Quartal 2026 ist abgerechnet — …», `VatPeriod::label()`). Debtor asks
  through its port — new `AccountingGateway::periodRefusal(date, withTaxLine)` (Null gateway:
  never refuses): a document with a tax code dated in a settled period is not issued
  («Fakturieren», an order's «Fakturieren», a correction that moves the date, the dunning fee),
  the mask shows a hint band, «Journal verbuchen» names the document it refuses (also for every
  other refusal of the bookkeeping — `JournalRefusedException::LEDGER`), and a payment's Skonto /
  Verlust there is refused with a sentence. The bank's refusal says what did not happen («Nichts
  gebucht» / «Nichts zurückgenommen»); the receipt adds «das Belegdatum in die laufende Periode
  legen». Nothing visible changes yet: no return can be posted. **Projects:** a class that
  implements `AccountingGateway` must add `periodRefusal()`. No migration.

- **module-financial (+ module-debtor, module-creditor, module-receipt, module-mandator):** no tax
  line before «Abrechnung ab» (VAT return stage S2 slice 4a, ADR-041 addendum 2026-11-24 point 3).
  While the mandator is liable AND «Abrechnung ab» is set, the ledger refuses a NEW posting with a
  tax code dated before that day — a posting, a manual entry, a reversal (also the bank's
  take-back), an issued invoice / credit note / dunning fee, a Skonto / Verlust, a creditor
  invoice, a receipt — and a change that gives an entry a tax line there or moves one earlier
  (reason `before-vat-start`). An existing tax entry before the day may still be corrected on its
  date or later, retracted and deleted; existing entries are untouched. Not liable, or no
  «Abrechnung ab» set → nothing changes. The sentence: «Vor dem 01.12.2026 wird in z77 keine MWST
  abgerechnet — eine Buchung mit MWST-Code geht dort nicht», each module adds its way out (debtor
  «… auf diesen Tag oder später legen», receipt «beim Beleg «ohne MWST» wählen»). **Visible at
  once** where a liable mandator already has the day set. The mandator help says so. No migration.

- **module-financial (+ module-vat, persistence-doctrine):** «MWST buchen» as SERVICES — VAT
  return stage S2 slice 5a; no screen calls it yet (the buttons and dialogs follow with slice 5b).
  `VatReturn\VatReturnPostingService`: `plan()` (lock-free: the form, every reason it cannot be
  posted, the settlement entry, the fingerprints), `post()` (under the locks of the ledger: the
  settlement entry dated the period's last day — each VAT account cleared by the coded tax, the
  payable amount as declared on 2201, the Rappen difference on 6945, no entry for a nil return —,
  the return with its declared figures, the protocol, and the voucher PDF archived write-once under
  `data/archive/financial/vat-return/`), `setFiled()` («Eingereicht am»). Refusals with a reason and
  one German sentence (`not-next`, `not-elapsed`, `already-settled`, `year-closed`,
  `account-missing`, `blocked`, `warnings-unconfirmed`, `figures-changed`, …). New
  `LedgerService::vatReturnAccount()`, `NumberRangeRepository::lock()`, `FormMapping::$sha256`.
  **Visible at once:** the year close of a LIABLE mandator with «Abrechnung ab» set is refused while
  a VAT period ending in the year is not settled (`Close\UnsettledVatPeriodsCheck`) — and there is
  no button to settle it before slice 5b: do not deploy 5a alone (plan deploy gate: S2 as a whole).
  Without «Abrechnung ab» nothing changes. No migration.

- **module-financial:** lifting a posted VAT return («Aufheben») as a SERVICE — VAT return stage S2
  slice 6a; no screen calls it yet (the confirm window and the admin pin follow with the screens).
  `VatReturnPostingService::lift(returnId, version, reason)`: only the LATEST live return; the
  settlement entry is reversed through the ledger (a visible reversal entry dated the entry's own
  day, «Aufgehoben: MWST-Abrechnung … — {Grund}»; a nil return writes none), the return becomes
  «aufgehoben» with who / when / why, the period is open again and the next «MWST buchen» posts it
  as the next revision («(Neuerstellung n)», its own voucher); the archived voucher stays
  untouched; one protocol row `lift`. Refusals with a reason and one German sentence
  (`no-reason`, `reason-too-long`, `not-found`, `already-lifted`, `stale`, `not-latest`, `filed`,
  `year-closed`). Same lock order as posting (one place). Nothing visible. No migration.

### Removed

- **module-financial:** `PeriodState::VatSettled` (`vat-settled`) and the month badge «MWST
  abgerechnet» of the fiscal-year list — nothing ever set the state.

### Fixed

- **module-financial (+ module-mandator):** «MWST buchen» / «Aufheben» as services (VAT return
  stage S2 slices 5a / 6a, still without a screen) — the findings of an independent review. Every
  state the plan calls postable now ends in a refusal with one German sentence, never in an
  exception: a VAT account of the settlement entry inactive, a group or unknown is a blocking
  `account-missing` in the plan; a period that overlaps a posted return (rhythm or «Abrechnung ab»
  changed after returns were posted) is refused `period-overlaps` instead of a unique-index
  violation; a refusal of the ledger at the last step (also when lifting) is `ledger-refused`. The
  writer row `vat-return` is created by a migration, so a refused first post can no longer leave a
  second one with a deadlock (1213). After its locks the writer re-reads the mandator
  (`CurrentMandator::fresh()`), reads the year and the lifted return as scalars, and decides on
  the account flags read under the lock. `mapping_hash` comes from the mapping the figures were
  computed with. One list of the VAT-account categories (`VatReturnService::vatAccountCategories()`)
  for the reconciliation and the lock. The year-close hint also names settings that no longer
  yield periods although returns are posted or «Abrechnung ab» is set (no block — not decided).
  Migration `Version20261125100000` (data only). **Projects:** run `php vendor/bin/z77-db migrate`.

- **module-mandator:** the PDF letterhead — invoices, credit notes, dunning notices, quotes,
  confirmations, delivery notes — prints the mandator's UID: «CHE-… MWST» while liable to VAT,
  without «MWST» otherwise, no line without a UID (MANDATOR-PDF-UID-001; Art. 26 MWSTG asks for
  the number on an invoice — the PDF variant of the letterhead had omitted it). The UID is now a
  required field while «Mehrwertsteuerpflichtig» is on (owner 2026-11-24): an existing liable
  mandator without one keeps loading and printing and must add it on its next save. PDFs issued
  before are not rendered again.
- **module-debtor:** a dunning fee of a mandator liable to VAT now carries VAT (owner 2026-11-24,
  ADR-041 addendum point 11, DEBTOR-FEE-VAT-001). The tax authority counts the fee to the
  consideration of the dunned supply (MWST-Info 04, 3.8.2.3); until now it was posted without a tax
  code and under-declared turnover and tax. The fee document takes the dunned invoice's tax code —
  with several codes the one of its largest share — at that invoice's snapshot rate (an invoice at
  7.7 % gives a fee at 7.7 %) and its service date; the level's fee is the amount the customer pays,
  the tax is extracted (20.00 at 8.1 % = 18.50 + 1.50). Posted on the dunning-fee account (6950)
  with the tax data on the net line and the tax on the VAT account, printed with the MWST column and
  summary. Unchanged: a mandator not liable, an invoice without a tax code (zero-rated / exempt only:
  the fee follows that code at 0 %). Fee documents issued before are not touched.
  `InvoicingService::chargesVat()` takes the dunned invoice as a third parameter;
  `InvoiceDraft::fee()` takes `dunnedInvoiceId` and drafts in gross price mode.
- **module-debtor:** a discount or loss on an invoice rounded DOWN by the 0.05 rounding split
  wrongly (DEBTOR-LOSS-SPLIT-001, vat-return review 2026-11-24). `PaymentPostingBuilder` weighted
  the uncoded part with its absolute value, so the rounding share was posted as a debit and the
  coded base came out too small — a full write-off of 92.53 + 7.49 − 0.02 left 0.04 of base as
  turnover. Now the coded part is amount × coded gross / gross total and the uncoded rest keeps its
  sign (a credit when negative). Entries already posted keep their error (at most 0.04 base /
  0.01 tax per document); saving the payment again re-posts it.
- **module-debtor + module-mandator:** a commission kept back by a card terminal, a payment
  provider or a bank no longer has to be cleared as «Skonto» or «Verlust», which reduced the
  invoice's turnover and VAT (owner 2026-11-24, ADR-041 addendum point 15, DEBTOR-FEE-KIND-001 —
  MWST-Info 07, 1.1: such fees are not deducted from the consideration). The payment form has a
  third way, **«Gebühren»**, between Skonto and Verlust: posted on the new mandator account
  «Gebühren Zahlungsverkehr» (KMU 6940 «Bankspesen», tab «Konten») against the receivable,
  without a tax code — turnover and VAT stay as invoiced. «Rest als Verlust» takes what is left
  after it; «Zahlbetrag» counts the money that arrived. Settlements booked earlier as Skonto /
  Verlust for a commission are not changed — move the amount to «Gebühren» in the payment.
  `PaymentDraft` takes `charges:` as a named argument; `AllocationKind::Charges`. Migration
  `Version20261124160000` (the column defaults to 6940). **Projects:** run
  `php vendor/bin/z77-db migrate`.

## [2.1.1] — 2026-11-22

### Changed

- **module-backend:** the rail starts directly under the action cell on every page (owner
  2026-11-21, SHELL-RAIL-TOP-001). Tab row and crumb line stand in the work column only; their
  island cells over the rail are gone, so a page with tabs no longer pushes the rail 39px down.
  «Dashboard» is the rail's first row (`.be-shell-home`, sticky; before: `.be-shell-crumb__home` in
  the crumb row), then the rail-top selection, then the menu; on a phone it rides in the drawer.
  The dashboard keeps its own start under the top bar. **Projects:** `.be-shell-crumb__slot--1`,
  `.be-shell-tabs__slot--1` and `.be-shell-crumb__home` no longer exist; `.be-shell__resizer` and
  `.be-shell-col--1` sit on `grid-row: 3 / -1`. Run `composer install` so `public/assets/backend`
  gets the new `base.css`.

### Fixed

- **module-backend:** on a phone a wide toolbar or list made the backend wider than the screen and
  pushed the action square off it — the phone grid column is `minmax(0, 1fr)` now (was `1fr`).
- **module-backend:** a long item ran out of the action cell's menu into the work column — the
  menu is at least as wide as the button and grows with its longest item, up to 24rem (then the
  text wraps); on a phone it stays on the screen (owner 2026-11-22, SHELL-ADD-WIDTH-001).

### Docs

- ADR-033 addendum 2026-11-21, `css-backend.md` SHELL-RAIL-TOP-001, `backend-screen.md` §1 regions,
  `shell-regions.svg`; new browser harness `tests/backend-shell-browser.php` (group `backend`).

## [2.1.0] — 2026-11-20

The first additions after 2.0.0, from the owner's fresh-install test: the logo set-up window, the
backend session owned by the framework with «Auf diesem Gerät angemeldet bleiben», the document of a
journal entry, the pending state for every request, and an article without VAT code or price.
**Upgrading from 2.0.0:** run `z77-db migrate` (one migration, module-mandator); every backend user is
signed out once (sessions move to `var/lib/sessions`); a project that had logo PATHS on the mandator
uploads its logo once in the window «Firmenlogo» — until then documents and mails print without logo.
`dev-main` is aliased `2.x-dev` from now on (was `2.0.x-dev`).

### Added

- **kernel/shared, module-backend, module-frontend, module-member:** a standard that shows a request
  went to the server (owner 2026-11-20 — without it users clicked twice). `core.js` «pending state»
  (`_Z77.core.pending`), one mechanism for every path: the control that sent the request is locked
  at once and shows a small spinner beside its text (in place of the icon on an icon-only control);
  the part that will be replaced (form, fetch region, window body) is `aria-busy`, inert at once and
  faded only after 300 ms; a thin bar at the top edge after 300 ms. Covers fetch forms and triggers,
  window openers and window forms, fetch-region links and forms, and full page loads (a same-tab link,
  a form the browser sends itself). Released on every outcome (the error message as before), after
  15 s for a page load that never comes, and on a back-forward-cache restore; background requests
  stay quiet; `prefers-reduced-motion` stills the spinner. Opt-out `data-no-pending` (set on the
  Backups, Dokumente and «Alle als ZIP» downloads). Styles: `kernel/shared` `_pending.scss`, bound
  by each host. `public-form.js` keeps its own disable only without core.js; the member area's
  pause switch uses the shared busy guard. UX only — the server keeps its own checks (Rule 7).

- **kernel, module-backend:** the backend session belongs to the installation, and «Auf diesem
  Gerät angemeldet bleiben» (owner 2026-11-20: the production host signed him out after about ten
  minutes — the session files lay in the host's shared session directory and died by its garbage
  collection). The session files now live in `var/lib/sessions` (shared across releases, `0700`,
  outside the backups), with their own collection. A backend login ends by two clocks the framework
  checks on every request: 60 minutes idle, 12 hours absolute; the login then says «Aus
  Sicherheitsgründen abgemeldet — bitte neu anmelden.» and returns to where the user was. New
  checkbox on the login: a device cookie (selector + validator, only a hash stored, rotated on
  every use, a reused old value ends the user's sessions and devices) signs in without the
  password for 30 days after the last full login, by default without the TOTP code as well.
  Abmelden forgets the device; a password change, a reset, a role change or a 2FA reset end all of
  the user's devices. Config, all in `config/client/auth.inc.php`: `sessionIdleMinutes`,
  `sessionLifetimeHours`, `rememberDevice` (false: no checkbox, no cookie accepted),
  `rememberDeviceDays`, `rememberSkipsSecondFactor`. **Existing installations:** nothing to add —
  `var/lib` is shared already, missing keys read their defaults; the first deploy signs everybody
  out once. A project with its own `backendConfig` must add `'authBridges' => [DeviceBridge::class]`
  to get the checkbox. The member door's device cookie now runs on the same kernel carrier
  (`Z77\Shared\Auth\DeviceCookie`), unchanged in behaviour. Not seen on a real host yet.
  Harness: `tests/backend-remember-device.php` (group `auth`). → login.md LOGIN-SESSION-001,
  LOGIN-REMEMBER-001.- **module-mandator, module-dms, module-frontend, kernel:** the logo set-up window «Firmenlogo» —
  opened from the setup step «Firmenlogo … hinterlegen» on the dashboard and from Mandant ›
  Briefkopf. One upload («Ein Logo für alle», default) or one per use — Belege (PDF), E-Mail,
  Anmeldung, Website; PNG / JPG / GIF up to 5 MB, judged by content (no SVG). The server derives
  the variants with gd (print up to 2000 px, e-mail / login 2× display size ≤ 96 KB, website 2×
  header size), re-encoded (metadata stripped), stored in the DMS: «Firmenlogo» protected (admin
  reads), «Website-Logo» public. Readers take only the DMS documents (the old path fields are gone,
  see «Changed»). A replaced or
  removed logo goes to the DMS trash. Migration `Version20261120150000` (expand only: four
  nullable document ids, `logo_step_dismissed`). The default frontend header and footer show the
  website logo (new kernel registry `siteLogo`, `Site\SiteLogo`; a project with its own header /
  brand mark shows «eigenes Design»). «Später / kein Logo» silences the setup step.
- **kernel, module-backend:** a setup step may open its target as a window
  (`SetupCheck::opens()`, `DashboardEntry::pending(…, opens:)`); `InlineImage::fromFile()` takes a
  density (a 2× file shown at half its pixels); `ModuleDrive::publicUrl()`; CSS
  `.be-reveal__panel--off`, `.be-list__preview`.

### Changed

- **module-mandator — upgrade note:** the old logo path fields are gone. **Projekte mit
  Logo-Pfaden laden das Logo einmal im Fenster «Firmenlogo» hoch; bis dahin drucken Belege und
  E-Mails ohne Logo.** «Logo (Pfad)» / «Logo (E-Mail, Pfad)» left the Mandant form (field,
  validator, help) and the window's list («Pfad: …»); PDF letterhead, e-mail, login and website
  read ONLY the window's DMS documents; the setup step counts only documents, so such a project
  sees «Firmenlogo hinterlegen» after the deploy. The columns `logo_path` / `email_logo_path` stay
  in the database, unused (expand/contract — a later release drops them); no migration.
- **module-mandator:** the login logo (`brandLogo`) is now `Logo\LoginLogo` (the login variant,
  else the e-mail variant); the setup step `mandator.logo` counts every use the installation shows
  (the website too) and reads «Firmenlogo hinterlegen» / «Firmenlogo für … hinterlegen».
- **module-mandator:** the logo window says in one line when the project renders Anmeldung /
  Website with its own design («… eigenes Design dieses Projekts — das hochgeladene Logo erscheint
  dort nicht.»), the save message names those uses, the help explains the two ways out.
- **module-financial, module-debtor:** row icons lead the row (owner 2026-11-20): the journal's
  «Beleg» icon right after the state icon, the posted invoice journals' «Journalliste (PDF)» and
  the dunning runs' ⋮ first — no trailing icon column.

- **module-financial, module-receipt, module-creditor, module-debtor:** the finance journal shows
  the document behind a generated entry — a «Beleg» icon in the list (new tab) and «… öffnen» in
  the entry's window: the receipt photo, the supplier PDF, the issued invoice / credit note / fee.
  Only what the user may open (DMS read right, the invoice screen's role). New registry
  `entryDocuments` (`Ledger\EntryDocumentProviderInterface`); financial names no module.
- **module-dms, module-receipt, module-creditor:** deleting a captured receipt or an unposted
  creditor invoice moves its document to the DMS trash (restorable) through the new
  `ModuleDrive::trash()` — no DMS right of the user needed any more. The receipt's switch «Foto
  auch löschen» is gone; a photo a split part still uses stays, and so does the PDF of a
  discarded duplicate («Verwerfen …»).
- **module-article:** the price is optional — empty = no price row, the order line sets it (the
  list shows «–»; on an edit an emptied price writes nothing). While the mandator is not liable to
  VAT the tax code is optional too, and the article window hides «MWST-Code» and «Preis ist».
  New setup step 61 «Artikel ohne MWST-Code» (open only while liable).
- **module-vat:** the setup step «MWST-Codes: Konto fehlt im Kontenplan» is silent while the
  mandator is not liable to VAT. **module-mandator:** `CurrentMandator::liableToVat()` is the one
  answer «liable or not» (no record = liable); debtor's `chargesVat()` reads it.
- **module-receipt, module-debtor (help):** the payment-method option reads «— keines (Kasse,
  privat) —»; the help of Zahlungsarten and Zahlungsziele explains the cash box and a cash sale.
- **module-dms, module-receipt, module-creditor:** role admin reads the DMS partitions «Belege»
  and «Kreditorenrechnungen» (receipt photos, supplier PDFs — before only the super user). A module
  declares such grants under the new config key `dmsPartitionGrants`; module-dms applies each
  once per installation, an existing one on the first DMS use after the deploy, and never
  re-grants what a person removed in the Drive.
- **kernel shared, module-backend, module-mandator:** the backend login screens (login, forgot,
  code, reset, logout) show the mandator's «Logo (E-Mail)» instead of the site name, inlined into
  the page; no logo, a file above 96 KB or any error → the site name as before. New config key
  `brandLogo` (`Site\BrandLogoSource`), opt-in per call (`partials/brandMark` with `'logo' =>
  true`); a project override of the partial still wins. The setup step reads «Firmenlogo für
  E-Mails und Anmeldung hinterlegen».
- **module-order:** the refusal of «Fakturieren …» when the QR payment part cannot be drawn no
  longer suggests «kein Zahlteil» on the order (gone since 2026-10-18); it names the payment
  target, the mandator and, for a cash sale, the invoice editor.

## [2.0.0] — 2026-11-20

First release with the business suite on Packagist. Newly published (14 packages, now 19 split
targets with the web stack and `z77/docs`): `z77/persistence-doctrine`, `z77/module-vat`,
`-contact`, `-mandator`, `-financial`, `-article`, `-debtor`, `-order`, `-receipt`, `-mailing`,
`-creditor`, `-member`, `-api` and `z77/kibot`. The web stack (`kernel`, `module-frontend`,
`module-backend`, `module-dms`) and `z77/docs` move from 1.6.0 to 2.0.0 with them.
Upgrading a 1.6 project: [`docs/03-development/upgrade-1.6-to-2.0.md`](docs/03-development/upgrade-1.6-to-2.0.md)
— the changelog starts 2026-11-15, the breaking changes before that date are listed there.

### Added

- **module-backend, kernel (installer):** the way to the first admin after a non-interactive
  install is no longer hidden. While `data/framework/auth/SETUP_TOKEN` exists and no backend user
  does, `/login` shows «Noch kein Admin angelegt → Erstes Setup. Den Setup-Token findest du in
  data/framework/auth/SETUP_TOKEN.» with a link to `/backend/system/setup/setup`, and the installer
  ends its run with the token file and the URL (instead of a block mid-output). The token itself is
  never shown. Token path, setup URL and the «pending» rule now live in one class,
  `Z77\Shared\Auth\FirstAdminSetup`, used by the installer, `SetupController` and `LoginController`.
  New handbook section «First Login» (`docs/01-handbook/installer.md`), linked from the new-project
  manual, the dev environment page and INSTALL-AI.md step 7 (LOGIN-SETUP-HINT-001,
  INST-SETUP-HINT-001).
- **module-vat, module-debtor, module-receipt, module-backend, module-creditor:** further first-setup
  steps — «MWST-Codes: Konto fehlt im Kontenplan» (`vat.accounts`), «Zahlungsziel mit IBAN
  erfassen» (`debtor.payment-target`; the dashboard line «Kein Zahlungsziel mit IBAN» moved out of
  `DebtorDashboard`), «Zahlungsart erfassen» (`receipt.payment-method`), the e-mail sender / transport
  / switch (`mail.sender`), «KI-Schlüssel fehlt» (`creditor.ai-key`).
- **module-backend, kernel, module-financial, module-mandator, module-article:** first setup
  («Ersteinrichtung», owner 2026-11-19) — the dashboard shows the next three open setup steps in
  setup order, each a line to its screen: «Kontenplan übernehmen», «Mandant erfassen» / «Mandant:
  Konten zuweisen», «Geschäftsjahr eröffnen», «Artikel erfassen», bank accounts without a valid
  ledger account, the private / transfer accounts of a mixed bank account, «Firmenlogo …
  hinterlegen», «Eröffnung {code} nicht erstellt»; a done step disappears and the next appears.
  New registry `projectChecks` (kernel `ProjectHealth\ProjectChecks`, extension file
  `projectChecksConfig.inc.php`), check base `SetupCheck`, bridge contributor
  `ProjectHealthDashboard`, optional `backendConfig` key `dashboardSetupSteps` (default 3). The
  dashboard lines «Mandant nicht erfasst» and «Eröffnung … nicht erstellt» moved into the setup
  steps; «Kein offenes Geschäftsjahr für heute» now appears only once a fiscal year exists.

- **module-financial:** bank account switch «gemischt geführt (privat und geschäftlich)», beside
  «Kontoauszug einlesen». Off (a purely business account): no «Privat …», no tabs «Geschäftlich» /
  «Privat», no column «Stand», «Privat» refused — «Zu prüfen» stays. Switching off is refused while
  something is private. Migration `Version20261119100000`: existing accounts with a private
  decision start ON. Column «Original» renamed «FW» (owner 2026-11-19).
- **module-debtor:** the lists say when, how much and through which payment target a document
  was paid (owner 2026-11-19). Aufträge › Rechnungen, tab «Verbucht»: columns «Bezahlt am» (it
  also shows the payment state — empty = open, a date = paid, «· Teil» = partly paid,
  «gutgeschrieben»), «Zahlbetrag» and «Zahlziel»; an invoice with a booked credit note names it.
  Debitoren: columns «Bezahlt am» (searchable, sortable) and «Zahlziel».
- **module-creditor:** invoice window — field «Kurs» for an invoice in a foreign currency. With
  «Betrag CHF» empty the amount is computed on save (amount in currency × rate, rounded to the
  Rappen); the rate is stored with the invoice. For the case where one card payment covers
  several invoices and the bank shows only the total and the rate (owner 2026-11-18).
- **module-financial:** Finanzen › Bankkonto — a click on «camt.053 Upload» first shows, per bank
  account, how far it is read and from which day the next statement must be downloaded («Laden
  ab»); files dropped onto the page are read at once, as before. Statements of accounts that are
  not set up as a bank account (a bank's ZIP carries every account of the contract) are skipped
  and named in one neutral line instead of an error per account (owner 2026-11-18).
- **module-financial / module-debtor:** a collective credit of QR payments on the account customer
  payments arrive on can no longer be set «privat» — one «Privat» made every such credit private,
  the coming ones too. Modules claim such movements through the new registry `bankMovementClaims`.
- **kernel shared (upload):** `UploadPolicy::$before` — the action-cell button opens a window
  before the file dialog; `UploadPolicy::inputId()`.
- **module-financial:** bank-driven bookkeeping, stage C4 (ADR-061, owner 2026-11-17) — the date
  «Abgleich ab» per bank account (with «Kontoauszug einlesen» on, the account is bank-driven from
  that day; movements before it, and every movement of an account without the date, are
  information only); the anchor «Bank … · Fibu … · Differenz …» confirmed once and computed over
  the year change; the check per bank account and period (change of the ledger account = business
  movements, postings without a movement named); the window «Abgleich …» in Finanzen › Bankkonto;
  the year close and the VAT return are BLOCKED while the statement is not read to the period end,
  movements are «zu prüfen», the check fails or the transfer account is not at zero, and the VAT
  return shows «n Buchungen mit Vorsteuer ohne Beleg» (migration `Version20261117200000`:
  `bank_account.reconcile_from`, `bank_anchor`; registry `bankEntryOwners` for the modules that
  post for a linked record).
- **module-mandator:** two accounts on the tab «Konten» — «Privat (Privatbezug / Privateinlage)»
  and «Übertrag eigene Bankkonten» (migration `Version20261117190000`), read by «Geschäftlich
  buchen …» and the bank check; no start value.
- **module-receipt:** a receipt paid by bank or card finds its bank movement (ADR-061 stage C5) —
  «Zahlungen suchen …» in the Belegjournal proposes the movement of the same amount around the
  receipt date (ticked when unique, candidates otherwise, a foreign amount as a candidate whose
  CHF comes from the bank), «Zahlung suchen …» / «Zuordnung lösen» in the receipt window; the
  movement becomes «geschäftlich» (on a bank account with «Abgleich ab», from that day), and the
  bank check counts the posted receipt as booked for that movement. No migration.
- **tests:** the test runner `php tests/run.php <group|harness …>` / `--changed` / `--list`
  (`npm run test`, owner 2026-11-17, topic `testing.md`) — harnesses side by side (`--jobs`,
  longest first), one log per harness in the temp folder, seconds per section from the
  timestamped output (no harness change), a watchdog that kills a silent or overlong harness
  tree and reports it `hung`, a tree guard that names migrations and untracked files a run left
  in `packages/` / `tests/`, one summary table + `result.json`, exit ≠ 0 on red / hung / stray;
  groups in `tests/groups.php` (every harness in one, orphans fail `--list`), coverage read from
  each harness's source; refuses a tree whose `vendor/z77` links point elsewhere (worktrees);
  self-test `tests/run-selftest.php`. Rules for builders in `testing.md` and `CLAUDE.md`.
- **module-creditor:** the line text («Kontierungstext») per creditor (owner 2026-11-17) — an empty
  single line gets the creditor's STANDARD text (set with «als Standard übernehmen», which now
  takes the first line's text too, or the new creditor field «Kontierungstext»), else the text
  under the learned column header (e.g. «Description», continuation lines appended: «Studio Max ·
  Nov 14–Dec 14, 2026»), else the slip's / the AI's text, else the creditor's last saved text; the
  header is learned on save and is optional for the trust phase (migration `Version20261117170000`).
- **module-financial:** a bank movement can be LINKED to what it pays (ADR-061 stage C1): table
  `bank_link` with an amount and the owner as a key (migration `Version20261117100000`),
  `Bank\BankLinkService` as the port of the owning modules; Finanzen › Bankkonto shows the column
  «Zuordnung» and the view «Ohne Zuordnung».
- **module-creditor:** an invoice finds its payment on the bank statement (ADR-061 stage C1) — a
  QR / RF reference links by itself, otherwise «Zahlungen suchen …» proposes (ticked) or lists
  candidates; «Bezahlt am» and, in foreign currency, the CHF amount come from the bank; posted
  invoices are linked without a new posting; «Zuordnung lösen»; creditor field «Banktext», typed
  or learned (migration `Version20261117110000`).
- **module-debtor:** the camt.054 import keeps the bank's ENTRY reference (`Ntry/AcctSvcrRef`)
  per payment — the key to the camt.053 collective credit (ADR-061 C3a) — and archives every
  imported camt.054 file unaltered under `data/archive/debtor/bank/<YYYY>/` (path and SHA-256 on
  the message; migration `Version20261117153000`). **kernel shared:** `Files\WriteOnceStore`,
  the write-once file store of the archives.
- **module-debtor:** the payments of a camt.054 entry find their camt.053 collective credit
  (ADR-061 C3b) — linked by themselves when the bank's entry reference, the sum and the number of
  payments agree (at the camt.054 import, or by the new job «Debitoren-Bankabgleich» when the
  statement comes later); a disagreement is never linked; payments read before 2026-11-17 get a
  proposal. Zahlungseingänge › «Bankabgleich …» confirms proposals and releases a wrong one;
  Finanzen › Bankkonto shows «Zahlungseingang · n Zahlungen». Nothing is booked by a link.
  **module-financial:** `BankLinkService::freeMovementsByEntryRefs()`.
- **module-financial:** every bank movement is «zu prüfen», «geschäftlich» or «privat» (ADR-061
  stage C2) — nothing is private by silence. Finanzen › Bankkonto: the column «Stand», the views
  «Alle» · «Zu prüfen (n)» · «Geschäftlich» · «Privat» (they replace «Ohne Zuordnung»), a window
  per movement and «Privat …» for ticked rows. «Privat» remembers the merchant or counterparty
  (optionally only a similar amount / the day of the month), so its coming movements are private
  too; a document that finds such a movement makes it business and asks «Die restlichen … auf
  ‹zu prüfen› setzen?» (Ja → «gemischt», Nein → an exception). «Geschäftlich buchen …» posts a
  movement (contra account, «Privat» or «Übertrag eigenes Konto», optional VAT code; with a VAT
  code from CHF 100 a file is required and archived write-once under
  `data/archive/financial/bank-voucher/<YYYY>/`); account and VAT code are proposed the next
  time. «Buchung zurücknehmen» reverses it — refused in a closed year or, with a VAT code, a
  settled VAT period. Config `bankPosting` (threshold, private and transfer account); migration
  `Version20261117170000` (`bank_pattern`, `bank_movement_mark`, `bank_posting`,
  `bank_movement.pattern_key`).
- **module-creditor (in progress):** supplier as a role «Kreditor» of a contact — several IBANs,
  posting defaults, master-data screen Stammdaten › Finanzen › Kreditoren.
- **module-creditor (in progress):** creditor invoices with lines; backend PDF upload (sniffed,
  size-limited, the same file never twice by SHA-256) stored in the DMS; Finanzen ›
  Kreditorenjournal with the PDF beside the fields.
- **module-creditor (in progress):** «Bezahlt am …» for one or several invoices and «Journal
  verbuchen …» as a run up to a cut-off date (direct posting).
- **module-creditor (in progress):** recognition core for the Swiss QR payment part from the PDF
  text layer, and an AI recognition core — both not wired into the screen yet.
- **module-financial:** `GrossEntryBuilder` — one module-agnostic builder for postings from
  gross amounts (VAT split, rounding, balanced lines); used by module-receipt and the one-line
  journal entry.
- **Backend help:** «Hilfe ergänzen» — an admin adds his own text to any help; it is shown under
  «Ergänzung» and becomes part of the KI-Assistent's knowledge.
- **Backend help:** help for every backend screen that needs one, and base-knowledge guides
  for the KI-Assistent.
- **module-debtor:** Zahlungseingänge take a ZIP of camt.054 files; the list has the tabs
  «Offen» (default) and «Erledigt» — a message is done when every transaction is booked or
  ignored.
- **module-order:** the list splits «Abgeschlossen» into «Fakturiert», «Gebucht» and «Erledigt».
- **module-receipt / module-dms:** a posted receipt's photo is kept 10 years (end of the
  fiscal year of the receipt date + 10).
- **module-member:** «Ablehnen» cleans up like an account deletion (project hook, tokens,
  pending logins, listeners) and is logged.
- **module-creditor (in progress):** «Jetzt erkennen» in the invoice window re-runs the
  recognition (only empty fields); the header above the QR slip fills invoice number and dates
  from labels (DE/FR/IT/EN, «zahlbar innert N Tagen») and a missing street from the letterhead;
  the QR message becomes the line text.
- **module-dms:** `ModuleDrive::read()` — a module reads back a document it stored in its own
  partition, nothing else.
- **module-creditor:** the journal shows «in Arbeit …» (or «KI liest …» while the AI reads) for
  an invoice the job is still recognising, in the column «Erkannt» and on top of its window; the
  list and the window update themselves (about every 15 s, never under a ticked row, a typed
  search or an open dialog) and stop when nothing is in progress or after 10 minutes unchanged.
- **module-backend:** `.badge--busy` — a breathing state pill for work the server does right now.
- **module-creditor:** invoices in a foreign currency (stage A0) — the invoice keeps its amount and
  currency (e.g. 9.00 USD), the CHF amount the bank or card debited is typed by hand and may stay
  empty (the invoice is then incomplete and not posted); the rate is derived and shown; posting in
  CHF with «… · 9.00 USD» in the text; the recognition fills the original amount + currency.
- **module-receipt:** `receiptConfig['foreignCurrencies']` (EUR, USD) and `ReceiptFields::foreignCurrencies()`,
  `foreignCurrency()`, `originalAmount()`, `derivedRate()` — the one currency list, used by module-creditor.
- **module-contact:** `ContactRepository::findSameName()` — the active contacts carrying exactly a
  given name (case and whitespace normalised), for a duplicate check before a new contact.
- **module-creditor:** «Löschen …» for the ticked invoices of «Im Journal» and «Gesperrt» — a
  confirmation names them; each goes completely (blocked ones too), the PDFs to the DMS trash, the
  same files may be uploaded again; an invoice changed or posted meanwhile is skipped and named.
- **kernel (listing, core.js):** «alle» in the head of a list with a selection
  (`Column::checkAll()`, `data-check-all`) — ticks every row of the page; first user the
  Kreditorenjournal.
- **module-creditor:** the switch «KI-Erkennung» beside «PDF hochladen» — the owner turns the AI
  recognition on and off; shown only where `creditorAi.enabled` releases the AI; never set = on;
  switching off stops every AI step not yet started (the text recognition runs on).
- **module-backend (CSS):** `.be-switch__label-more` — the tail of a switch label that leaves on a
  phone.
- **module-creditor:** learning per creditor — saving an invoice with its creditor (or «Kreditor
  übernehmen …») teaches the creditor what stays the same on its invoices (a foreign VAT number,
  the domain, the sender line) and the label before number, dates and amount; its next invoice
  without a known IBAN or Swiss UID is recognised and read by them. The AI reads along until PHP
  and the AI agreed twice, then that creditor's invoices are read without it. The creditor window
  shows what was learned, with «Gelerntes vergessen». Migration `Version20261116100000`
  (`creditor_learned`, `creditor_invoice_text`).

### Changed

- **All packages:** every `z77/*` requirement is `^2.0` and every `composer.json` carries the
  branch alias `dev-main` → `2.0.x-dev` (was `^1.0` / `1.0.x-dev`). A project that takes the
  framework through `path` repositories changes its `z77/*` requires to `^2.0`.
- **kernel (installer):** the docs offer of an interactive install requires `z77/docs:^2.0`.
- **kibot:** `composer.json` gains `homepage` and `support` (source `z77-ch/kibot`) like the
  other packages.
- **Monorepo tooling:** `terser` is a devDependency pinned to `5.26.0` (the version the VS Code
  extension «autominify» bundles), so `npm install` brings the JS minifier on a fresh machine;
  `npm run build:js` prefers it over the extension's copy — same bytes (JS-BUILD-004).
- **module-financial:** «Abgleich …» — on a bank account that is not «gemischt geführt»
  «Differenz bestätigen» is refused (server and window): a difference is corrected by a booking;
  the expected difference there is 0.00, a mismatch warns at the year close (owner 2026-11-19).
- **module-backend / kernel shared:** form windows no longer grow past their edge — the form grid
  sizes its columns `minmax(0, 1fr)` (a native file field pushed «Geschäftlich buchen …» wider,
  text and «Gegenkonto» ran over). The voucher of «Geschäftlich buchen …» uses the upload
  component's look (new `partials/uploadField`: one file that travels with its form, drag and
  drop, «entfernen») (owner 2026-11-19).

- **module-financial:** Finanzen › Bankkonto — the question when a document makes a movement of a
  private pattern business is reworded: ««X» ist als privat gemerkt — N weitere Bewegungen sind
  dadurch privat. Gibt es bei «X» auch Geschäftliches?», answered with «Ja — jedes Mal prüfen» /
  «Nein — nur diese Bewegung»; a pattern's name no longer carries one movement's date and time
  (owner 2026-11-19).
- **module-financial:** the camt.053 import requires an OVERLAP — a statement file must start on or
  before the last stored day (owner 2026-11-18). A file that starts later, the day after
  included, is refused and names the day to download from. Before, a start on the following day
  was accepted. Several statements in one file still follow each other. Refusal reason `gap` is
  now `no-overlap`. In the list «Bewegungen», «Bestätigen …» and «Entscheiden …» are buttons.
- **module-creditor:** on a bank account with «Kontoauszug einlesen» and «Abgleich ab», «Bezahlt
  am» comes only from the bank from that day on — typing it in the invoice window and the bulk
  «Bezahlt am …» are refused with a sentence naming the bank account; other payment methods, a day
  before «Abgleich ab» and dates typed earlier are unchanged (ADR-061 decision 13).
- **module-financial:** the accounts of «Privat» and «Übertrag eigenes Konto» are read from the
  mandator record (Stammdaten › Firma › Mandant, Reiter «Konten») instead of
  `financialConfig → bankPosting` (which keeps only `voucherFromGross`); a bank movement has a
  state only on a bank-driven account from «Abgleich ab» — set the date after the update.
- **module-creditor:** lines that do not add up to «Betrag CHF» are REFUSED on save (owner
  2026-11-17 — the soft «Zeilen = Betrag» closed the window unseen): «Zeilen ergeben CHF 25.00,
  Rechnungstotal CHF 20.00 — Differenz CHF 5.00» under «Kontierung»; a line without amount or an
  empty «Betrag CHF» stays saveable as incomplete; a split foreign invoice whose lines miss the
  bank's CHF is refused when it takes its movement.
- **kernel shared (upload):** the `cell` shape's progress runs in a WINDOW instead of the page
  (UPLOAD-DIALOG-001, owner 2026-11-17 — twenty PDFs filled the Kreditorenjournal above the list
  and stayed): a run opens core's own window (new `_Z77.core.windows.openNode(node, {title,
  width})`) with «PDF hochladen · 3 von 20» in the title and the rows in its body; × / Esc close
  it at any time, the uploads go on, the button counts «3 / 20» and a click on it shows the window
  again. `[data-upload-progress]` removed from Zahlungseingänge, Kreditorenjournal and Bankkonto;
  `drop` / `field` unchanged. Browser harness `core-js-windows-browser.mjs` part 8.
- **module-creditor:** the job «Kreditoren-Eingang» starts a further AI recognition in the same
  pass while at least 30 s of its time budget remain (was: one AI invoice per pass).
- **module-creditor:** «Kreditor übernehmen …» gives the creditor also to every other open invoice
  whose proposal names the same party (same UID, an IBAN only this creditor holds, or exactly the
  same name — never with a different UID); the message counts them.
- **module-creditor:** a creditor without a default account takes the account (with its VAT) of
  the first line of a saved invoice, and «Bezahlt mit» when it has none — a set default is never
  overwritten; the message names what was taken (was: only the first complete invoice).
- **module-creditor:** the Kreditorenjournal has no intro band and no count line any more — the
  PDFs are dragged onto «PDF hochladen» (a dashed frame while they hang over it) or anywhere on the
  list; only the buttons stay above the list, their meaning in the help.
- **module-creditor:** the helps of the Kreditorenjournal, the invoice window and the guide
  «Lieferantenrechnungen» rewritten in plain words — short sentences, what you do and see, no
  internal terms.
- **kernel (core.js):** a live listener's `refresh-region` / `refresh-window` wait like `reload`
  while someone is at work; `refresh-window` without a window id reloads the window the answer
  came from.

- **Navigation:** deleting an entry also removes its aliases and metadata in all languages, is
  refused while a reference entry points at it, and the confirmation names what goes.
- **module-receipt:** a mandator not liable to VAT forces «ohne MWST» at posting; the app names
  the base currency and says «Journal verbuchen».
- **module-order:** the phase `cancelled` reads «Abgelegt» (was «Storniert»).
- **module-debtor:** «Gutschrift erstellen» only on a posted invoice; void invoices are refused
  with their own sentence; «Gutschrift löschen» for a credit note.
- **Installer:** a new project gets the module tree the packages really use (`src/Entities`,
  `src/Repositories`, `src/Validators`, `src/Services`, `src/App/Config`).
- **App:** a receipt tile asks the server first and opens the camera only when access allows it.
- **module-creditor:** the journal tab «Eingang» is now «Nicht eingelesen (n)».
- **Upload:** a success row shows the endpoint's message; a ZIP of messages read before says
  «keine neue Meldung».
- **KI-Assistent:** the panel stays usable while a window is open; the backend loads the bot's
  script and stylesheet with a version stamp, so a deploy reaches the browser without a hard
  reload.
- **Mail:** the footer divider is one thin line.
- **module-creditor:** the Kreditorenjournal («Im Journal», «Gesperrt», «Nicht eingelesen») asks
  for news every 15 s also when no row is in recognition — invoices from the intake folder and
  refused files appear without a reload; it stops after 10 minutes without a change.
- **module-financial (in progress):** bank account service, stage B1 as services — master data
  «Bankkonten» (IBAN, ledger account, the switch «Kontoauszug einlesen») and the camt.053 import
  with an own archive (`data/archive/financial/bank/<year>/`), an information source for the
  creditor journal. The import is seamless: a file that leaves a gap or whose overlap does not
  agree with what is stored is refused; an overlapping export adds only what is new. Migration
  `Version20261116140000` (ADR-060).
- **module-financial (in progress):** the bank screens — Stammdaten › Finanzen › Bankkonten (list
  and window with the switch «Kontoauszug einlesen») and Finanzen › Bankkonto (camt.053 upload
  in the action cell, answering with one sentence; one searchable list «Bewegungen» with the
  balance). The payment-method window offers «Bankkonto». The screens carry labels only — the
  explanations are in the help.
- **kernel:** one shared camt parser (`Z77\Shared\Payment\Camt`, camt.053 / .054, versions .04
  and .08) — debtor's camt.054 import reads through it, behaviour unchanged.
- **module-receipt:** a payment method can name a bank account and then takes its credit account
  from «Bankkonten»; payment methods without one behave as before.
- **kernel:** `composer.json` now declares `ext-openssl` (TotpVault, backend 2FA), `ext-iconv`
  (PdfDocument) and `ext-simplexml` (CamtParser) — used before, never declared; all three are in a default
  PHP build (owner 2026-11-19, release decision D3).

### Fixed

- **persistence-doctrine, module-financial, -debtor, -creditor, -order, -article:** every write of
  a versioned entity bumps its optimistic-lock version again under doctrine/orm 3.8 (DOCTRINE-VERSION-001).
  ORM 3.8 compares object values by their database value, so a `changed_at` re-stamped within the same
  second is no change: a write that changed only the lines / child rows (a journal entry, an order, a
  draft invoice, a creditor invoice, a payment's allocations) or only an article's price got no
  `UPDATE`, and the version did not move. New trait `Z77\Persistence\Doctrine\Mapping\WriteCounter`
  (column `write_count`, one migration per module) counts every write where the entity stamps
  `changed_at` — deterministic, independent of the clock and of the ORM's comparison.
- **module-financial:** on MariaDB 11.4 the change log's text search is case-insensitive again
  («bankspesen» finds «Bankspesen») and its text sort follows the collation — `JSON_UNQUOTE()`
  returns a binary collation there; the snapshot texts are compared in `utf8mb4_unicode_ci`.
- **persistence-doctrine:** on MariaDB 11.4 a number drawn after a swallowed deadlock is no longer
  committed outside the unit of work (a gap in a gapless range) — the server runs that statement in
  autocommit while DBAL still reports the transaction open. `NumberRange` `next()`, `create()` and
  `dropUnused()` now refuse with a `LogicException` before any statement once the unit of work is
  marked rollback-only; new `DoctrineTransaction::isRollbackOnly()` (internal) and
  `DoctrineRepository::isTransactionRollbackOnly()` for repositories that write on `connection()`
  (persistence-doctrine.md DOCTRINE-TX-008, `tests/persistence-doctrine-tx.php` N6–N9, O9b, O10).
- **module-mailing:** the same gap closed for the outbox — a state transition (`claim`, `markSent`,
  `markRetry`, `markFailed`, `cancel`, `requeue`, `failInterrupted`) inside a unit of work already
  marked rollback-only now refuses with a `LogicException` before any statement, so a mail cancelled
  by a replacement that rolls back stays queued. Outside a unit of work (the job) nothing changes
  (mailing.md MAILING-TX-001, `tests/module-mailing.php` P).
- **kernel (installer):** a `z77/module-*` package installed without its namespace in the project's `autoload.psr-4` is no longer silent — a WARNING after «installation complete» lists each such module with the exact line to paste (it gets no routes and no tables otherwise); warning only.
- **skeleton:** `composer dev` / `dev.bat` start `php -S 127.0.0.1:8080` — on Windows `localhost` listens on IPv6 only, so `http://127.0.0.1:8080` hung.
- **module-financial:** a fresh install on MariaDB 12.3+ (found on 13.0.2) no longer fails in `z77-db migrate`
  with error 1064 at `Version20261116140000` — the column `bank_statement.to_date` is a keyword since MariaDB 12.3
  (TO_DATE, MDEV-19683). The migration quotes it now (unchanged where it already ran); the new migration
  `Version20261119140000` renames `from_date` / `to_date` to `period_from` / `period_to` in one step (ADR-039
  decision 14 deviation, stated in the migration: with the single-database deploy, import no bank statement
  between `migrate` and the switch). Guard: `tests/schema-reserved-words.php` (group `persistence`) checks the
  mapping, every migration and raw SQL aliases against the MySQL 8.x / MariaDB ≤ 12.3 keyword lists
  (persistence-doctrine.md PERSIST-RESERVED-001, bank.md BANK-SCHEMA-001).
- **kernel (installer):** the admin password prompt ends on EOF — no terminal on stdin defers to the `SETUP_TOKEN`, an empty answer re-asks at most 3 times (was an endless loop under a nested `@composer install` in Git Bash); the skeleton's `dev` script runs `@composer install --no-interaction` and its `authors` are a placeholder (installer.md INST-PROMPT-EOF-001).
- **module-financial:** `z77-db migrate --dry-run` no longer fails on migration
  `Version20261117170000` — its `postUp()` writes directly and now does nothing when the column
  is not there (a real run is unchanged).
- **module-creditor:** the PDF recognition runs only in the job `creditor-intake`, never in the
  upload request — on a host whose web PHP forbids `proc_open()` an upload stayed unrecognised; an
  upload now shows «Erkennung ausstehend» and queues the job (migration `Version20261115140000`).
- **module-creditor:** «Kreditor übernehmen …» with an incomplete proposal (a QR slip without
  street) opens the prefilled contact form with the missing fields named instead of failing.
- **module-creditor:** «Kreditor übernehmen …» no longer creates a second contact of the same name
  (or for a UID a creditor already carries): it offers the existing one, pre-selected; a new one
  only with the explicit «Trotzdem einen neuen Kontakt anlegen».
- **Frontend:** the preview bar reads the URI through `Request` (Rule 4).
- **Help:** the help catalog line keeps the first heading and always the screen tag.
- **i18n:** the translation catalog saves under the i18n lock.
- **module-dms:** the edit dialog no longer claims the access rules apply only in «protected».
- **module-creditor:** an invoice deleted while the job recognises it is dropped quietly — no error,
  no «PDF nicht lesbar» note, and no AI call for an invoice already deleted.
- **module-creditor:** the tab «Nicht eingelesen» as a page or region load showed no files and
  its forms no CSRF token (the list template did not pass them to the region).
- **kernel/shared core.js:** a switch that saves itself (`data-fetch-toggle`, the backend's font
  slider) no longer counts as unsaved work once flipped — it held every live listener's refresh
  for the rest of the page (the Kreditorenjournal row stayed «in Arbeit …» until F5).
- **module-debtor:** the job `debtor-bank-link` is keyed by a literal in `debtorConfig` — the
  deploy check (`.releases`, reads configs without running them) did not see it.

### Removed

- **Installer / bootstrap:** the dead `apcuCachePrefix` default — installations are separated by
  the data cache's path-hash pool prefix.
- **Frontend:** the unused Google Fonts (Inter) link; the fonts slot stays empty for a project's
  self-hosted face.

### Docs

- Publishing status for 2.0.0: the root README, every package README, `INSTALL-AI.md` and the
  handbook (architecture, business-suite setup, new-project manual, optional modules) describe
  the installation from Packagist with `composer require`; `INSTALL-AI.md` loses the pre-2.0.0
  path-repository box. Path repositories are described only for work on the framework itself —
  new section «Working on the framework itself» in `docs/01-handbook/dev-environment.md`.
- `SECURITY.md`: supported versions name the 2.x line.

- **module-backend (dashboard «Ersteinrichtung»):** investigated a fresh install that showed
  «Mandant erfassen» without «Kontenplan übernehmen» in front of it (DASH-SETUP-FRESH-001,
  `docs/topics/backend.md`). Not reproducible with every module registered; reproduced exactly when
  `module-financial` is installed but not registered (no `Z77\Module\Financial\` line in the project's
  `autoload.psr-4`). New test `tests/project-health-fresh.php`: a fresh install end to end — every
  module's real config, the real `canReach()`, the first admin's roles, an empty schema — with the
  chart step first. No code change.
- **Handbook:** `dev-environment.md` §2 «PHP settings (php.ini)» — one table of the `php.ini`
  values the code needs (upload limits, `memory_limit`, execution time, APCu, OPcache,
  `allow_url_fopen`, `disable_functions`), each with its source, and the one complete extension list.
- **Release 2.0.0 — owner decisions (2026-11-19):** `module-creditor`, `module-member`, `module-api` and
  `kibot` ship in 2.0.0; the client names in old commit titles stay (no history rewrite); after the release
  the handbook explains only the Packagist installation. Recorded in `release-2.0.0-bauplan.md`, the
  roadmap and `packaging.md`.
- **Publication readiness (2026-11-19):** `INSTALL-AI.md` — an installation guide for an AI
  assistant, linked at the top of the root README and of every published package README; a
  `LICENSE` file in every package; one License section in all 18 READMEs, absolute links; the
  READMEs of financial, debtor, receipt, creditor brought to the current state; handbook: bank
  step in the business-suite setup, upload building blocks and form grid in the backend-screen
  recipe, PHP minimums made consistent, machine- and host-specific detail removed; client names
  removed from code comments and tests.
- **Handbook for the business suite (2026-11-19):** new pages `customize-business-module.md`
  (configure / extend / override, one section per business module), `bank-setup.md`,
  `jobs-and-cron.md`, `optional-modules.md` (member, api, kibot); release 2.0.0 prepared in
  `docs/03-development/release-2.0.0-bauplan.md` and `upgrade-1.6-to-2.0.md` (nothing released).
- **docs-lint:** `npm run docs:check` also checks `docs/01-handbook/` — unresolved links and
  anchors, machine paths, artifact URLs and client names (denylist kept outside the repository).

- **bank (ADR-061):** bank-driven bookkeeping decided (owner 2026-11-17) — every movement business,
  private or «zu prüfen», learned patterns, «Geschäftlich buchen …», creditor/debtor paid from the
  camt.053, close blocks; supersedes ADR-060 decisions 16/17 in part. Stages C1–C5 planned.
- Handbook checked against the code for the GitHub release: 18 packages, module discovery via
  the project's psr-4, templates API, installer defaults, conventions in English.
- Help texts, READMEs and topic docs of every business module checked against the code (second
  pass); customer data anonymised.
- New handbook page [`business-suite-setup.md`](docs/01-handbook/business-suite-setup.md) —
  packages, enabling, migrations and the first backend steps of the business suite.
- [`css-conventions.md`](docs/01-handbook/css-conventions.md) §3–§6 show the real token and
  class names (`--fe-*`, `--be-*` + the backend scale, `fe-`/`be-` blocks).
- [`patterns/slider.md`](docs/01-handbook/patterns/slider.md) is self-contained (minimal SCSS
  and JS, the framework pieces it builds on) and follows the Drive's editor order.
- [`backend-screen.md`](docs/01-handbook/backend-screen.md): «draw the screen first and agree on
  it» as the general rule; the board on the owner's canvas stays his practice.
- Build plan for module-creditor; ideas recorded (invoice intake automation, backend view
  sharing).
- Changelog started.

[Unreleased]: https://github.com/z77-ch/z77-framework/compare/2.1.0...HEAD
[2.1.0]: https://github.com/z77-ch/z77-framework/compare/2.0.0...2.1.0
[2.0.0]: https://github.com/z77-ch/z77-framework/compare/1.6.0...2.0.0
