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
  agree with what is stored is refused; an overlapping export adds only what is new. No screen
  yet. Migration `Version20261116140000` (ADR-060).
- **kernel:** one shared camt parser (`Z77\Shared\Payment\Camt`, camt.053 / .054, versions .04
  and .08) — debtor's camt.054 import reads through it, behaviour unchanged.
- **module-receipt:** a payment method can name a bank account and then takes its credit account
  from «Bankkonten»; payment methods without one behave as before.

### Fixed

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

### Removed

- **Installer / bootstrap:** the dead `apcuCachePrefix` default — installations are separated by
  the data cache's path-hash pool prefix.
- **Frontend:** the unused Google Fonts (Inter) link; the fonts slot stays empty for a project's
  self-hosted face.

### Docs

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
