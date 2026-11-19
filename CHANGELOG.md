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

Work of 2026-11-13 to 2026-11-15.

### Added

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

### Fixed

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

### Removed

- **Installer / bootstrap:** the dead `apcuCachePrefix` default — installations are separated by
  the data cache's path-hash pool prefix.
- **Frontend:** the unused Google Fonts (Inter) link; the fonts slot stays empty for a project's
  self-hosted face.

### Docs

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
