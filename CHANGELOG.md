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

### Changed

- **module-creditor:** the job «Kreditoren-Eingang» starts a further AI recognition in the same
  pass while at least 30 s of its time budget remain (was: one AI invoice per pass).
- **module-creditor:** «Kreditor übernehmen …» gives the creditor also to every other open invoice
  whose proposal names the same party (same UID, an IBAN only this creditor holds, or exactly the
  same name — never with a different UID); the message counts them.
- **module-creditor:** a creditor without a default account takes the account (with its VAT) of
  the first line of a saved invoice, and «Bezahlt mit» when it has none — a set default is never
  overwritten; the message names what was taken (was: only the first complete invoice).
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
