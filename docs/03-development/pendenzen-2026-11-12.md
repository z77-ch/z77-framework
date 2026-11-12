# Pendenzen — prioritized short list (2026-11-12)

**Status:** snapshot of 2026-11-12, after the sweep of every topic's `## pending`.
**Source of truth:** the topic docs (`docs/topics/*.md` → `## pending`). This list only orders
them; when an item is done, update the topic doc — this file is not maintained item by item.

**The sweep:** 313 pending bullets (≈ 246 KB) → 246 bullets (≈ 74 KB). Removed: «NOT committed»
claims (all found in git, hashes now in the bullets), «Resume here» / «Next session» logs, items
already built, the owner's browser confirmations of 2026-11-12, and every «migration to apply» note
for migrations already applied locally.

**Migration state (read-only `z77-db status`, 2026-11-12):** `z77ch_main` (z77.ch) has every
migration up to order `Version20261024100000`. `z77ch_initial` (z77.ch-initial) lacks three:
mandator `Version20261023120000`, debtor `Version20261023150000`, order `Version20261024100000`.
module-receipt (`Version20261112100000`) is registered in neither project. The live server: unknown.

---

## A — Owner must LOOK (browser / paper / device)

Ordered by how soon the screen is used. Each line names the screen; the steps are in the topic.

**Faktura (order + debtor + mailing)**

1. Faktura-Journal steps 5–6, order side — edit an order in the journal, «Neu fakturieren …» (revision / replacement), «Löschen …» → tab «Gelöscht», locked again after «Journal verbuchen». → [order.md](../topics/order.md)
2. Faktura-Journal, invoice side — «Kunde wechseln» on an issued invoice, «Rechnung löschen …», camt.054 with the reference of a replaced invoice. → [debtor.md](../topics/debtor.md)
3. Faktura-Journal step 8 — a replaced / deleted invoice cancels its queued mails, the successor goes out as `invoice-replacement`. → [mailing.md](../topics/mailing.md)
4. Stammdaten › Aufträge › Auftragsstatus on z77.ch — the rows «Fakturiert» / «Gebucht» with their switches (`OrderStatusUpgrade`). → [order.md](../topics/order.md)
5. Invoice editor positions after `1f0a6e2` — ⋮⋮ / ⇥ / ×, eyebrow, lump sum, credit note, final document. → [debtor.md](../topics/debtor.md)
6. Invoice ONE mask — a final invoice from «Verbucht», a draft's actions, the grey fieldset's legibility. → [debtor.md](../topics/debtor.md)
7. Send UX of the result list — «Alle versendet», a failed mail brings the button back, «Erneut senden …» in ⋮. → [debtor.md](../topics/debtor.md)
8. «Hinweis ohne MWST» — Mandant › UID und MWST, then Offerte / Auftragsbestätigung / Rechnungsvorschau / invoice PDF, facts row, credit note, dunning fee. → [mandator.md](../topics/mandator.md), [order.md](../topics/order.md), [debtor.md](../topics/debtor.md)
9. Order phases — quote → «Auftragsbestätigung …» → «Lieferschein …», «Storniert», tabs, status search (whether seen: unverified). → [order.md](../topics/order.md)
10. Debtor rebuild of 2026-10-13 — «Entwurf löschen …», refused booking print, role «Debitor» on/off, «Tage nach der letzten Mahnung», modal URL as a page, double-click on «Speichern» (whether seen: unverified). → [debtor.md](../topics/debtor.md)

**Mail and outbox**

11. Mail clients — the e-mail logo inline, the PDF attached, the text part's paragraphs in Outlook (desktop, web) and Gmail. Needs mandator `Version20261023120000` on `z77ch_initial`. → [mailing.md](../topics/mailing.md), [mail.md](../topics/mail.md)
12. Sender identity — System › Benutzer › «Absender für E-Mails», «Senden als», the `.eml` headers, Postausgang window. → [mailing.md](../topics/mailing.md)
13. Service › Postausgang — tabs, bands, ⋮ items by state, sandboxed preview, «Versand starten». → [mailing.md](../topics/mailing.md)
14. Service › E-Mail › «Beleg-Mails»; a DMS document sent over SMTP. → [mail.md](../topics/mail.md)

**Financial / mandator**

15. Geschäftsjahre › 2026 › «Eröffnung erstellen …», balance sheet 2026, account statement 1100; mandator tab «Konten». → [financial.md](../topics/financial.md), [mandator.md](../topics/mandator.md)
16. Journal — fetch region, Enter posting from both capture forms, phone drawer, the «MwSt» switch. → [financial.md](../topics/financial.md)
17. P5 part 1 live pass — close, debtor block, reopen with a reason, protocol, journal refusal. → [financial.md](../topics/financial.md)
18. Paper: the first QR-bill against the SIX portal and a bank's scanner app (needs the QR-IBAN). → [pdf.md](../topics/pdf.md), [debtor.md](../topics/debtor.md)

**Receipts and the phone app**

19. Belegjournal screens — «Foto auch löschen» off by default, no «Löschen …» on master data (module first registered in a project). → [receipt.md](../topics/receipt.md)
20. `/app` on an iPhone and an Android phone — camera, JPEG scaling, HEIC, home-screen install, offline queue, cookie separation on iOS; the «Geräte» screen (QR scan, live switch). → [api.md](../topics/api.md), [receipt.md](../topics/receipt.md)

**Backend look and controls**

21. ADR-051 look — order window light/dark, Artikel list, Adresstyp dialog, journal entry, Drive buttons, badges, dashboard/login, Import/Stats/ACL controls. → [css-backend.md](../topics/css-backend.md)
22. Forms rework and action standard — one dialog per module, field-less confirm, DMS edit modal, island cell, backup split picker, green confirm in dark mode, phone width. → [css-backend.md](../topics/css-backend.md)
23. Row ⋮ menus (MENU-001), list help in the toolbar (HELP-003), upload round two (Zahlungseingänge), the 2026-10-13 core.js changes. → [fetch.md](../topics/fetch.md)
24. Article — group tree hub and coordinator defaults, the article picker in the invoice row, the toolbar tree picker. → [article.md](../topics/article.md)
25. Jobs — the job row, ⋮ hub, schedule dialog (JOBS-UI-ROW-001). → [jobs.md](../topics/jobs.md)
26. Member profile rows (MEMBER-FORM-ACTIONS-001); login rest — stale tab with «angemeldet bleiben», 2FA with a real authenticator, alert mail, admin reset, lock after 5 codes. → [member.md](../topics/member.md), [login.md](../topics/login.md)

**DMS and content**

27. Drive — edit modal, bulk move/delete, «Papierkorb leeren», `?key=` mount; media picker incl. the slot editor's iframe page. → [documents.md](../topics/documents.md)
28. DMS-ZIP host checks — CRC speed, multi-GB download, macOS, 7-Zip; a `.php` in a partition is not run. → [documents.md](../topics/documents.md)
29. Content editor — DMS image blocks, media field, one click-through of the visual block editor. → [content.md](../topics/content.md)
30. Frontend PAUSE-1 — six pages × three breakpoints; zihlundsee contact page; the reference form on the skeleton. → [css-frontend.md](../topics/css-frontend.md), [forms.md](../topics/forms.md)
31. kibot live test with a real key (widget + backend panel). → [kibot.md](../topics/kibot.md)

**Owner / operator data tasks (not looks):** run the three missing migrations on `z77ch_initial`
(after the go); recovery e-mail addresses on the accounts ([login.md](../topics/login.md));
`canonicalBaseUrl` per installation ([member.md](../topics/member.md)); Service › Jobs on axo3 and
zihlundsee ([stats.md](../topics/stats.md)); copy `.releases/` into the other projects
([packaging.md](../topics/packaging.md)); import the «Jobs» / «Service» nav entries
([jobs.md](../topics/jobs.md), [backup.md](../topics/backup.md)); one real `z77-restore`
([backup.md](../topics/backup.md)).

---

## B — Owner must DECIDE

Top first: what blocks the next build or carries a production risk.

1. What do «die Listen» cover — the next item in the owner's order? → [order.md](../topics/order.md)
2. Empty `fromAddress`: warn on the confirm pages / Postausgang band, or pause the job instead of burning attempts? → [mailing.md](../topics/mailing.md)
3. A 0.00 draft with a payment target — leave the target empty; should the invoice editor refuse 0.00 like the order? → [debtor.md](../topics/debtor.md)
4. Receipts: keep or delete the photo with the receipt; HEIC refused; «ohne MWST» counts as decided; non-liable mandator (RECEIPT-VAT-001). → [receipt.md](../topics/receipt.md)
5. CE-SLOT-ESC-001: go for the fix (S, recommended next). → [content.md](../topics/content.md)
6. Confirm the decisions taken on the owner's behalf in financial (reconciliation warnings, module-only flag, `sourceRef`, «VAT settled»). → [financial.md](../topics/financial.md)
7. Mail defaults: subject «Rechnung {nummer} — {absender}», the booking print lands on the result list, the eye icon per order. → [mailing.md](../topics/mailing.md)
8. «nicht mehrwertsteuerpflichtig» beyond documents: hide the one-line MwSt row, no VAT return? → [mandator.md](../topics/mandator.md)
9. Usability review 2026-10-15 (debtor badge, row click, address name from the contact, «Rollen» first). → [contact.md](../topics/contact.md), [order.md](../topics/order.md)
10. Tag `1.7.0` now; ADR-046 addendum; when to tag after `1.6.0`. → [order.md](../topics/order.md), [css-backend.md](../topics/css-backend.md)
11. Contact editor as a window so «Debitor bearbeiten …» opens as one (DEBTOR-ROLE-001)? → [debtor.md](../topics/debtor.md)
12. Print the billing address's e-mail / phone on the PDFs? «Hinweis ohne MWST» on the dunning notice? → [debtor.md](../topics/debtor.md)
13. «Betreffname» default: the mandator mask or a setting per debtor? Payment status `credited`? DEBTOR-TEXT-DROP-001? Refuse `invoice()` in a closed year up front? → [debtor.md](../topics/debtor.md)
14. Backend group for contacts: `contact`, `finance` or «Stammdaten»? → [contact.md](../topics/contact.md)
15. The app's name instead of «z77 App» (API-008 c); a module-agnostic «per device» link (API-010 a). → [api.md](../topics/api.md)
16. Stats: count only the canonical host; an IP exclusion list. → [stats.md](../topics/stats.md)
17. DMS: gate changes and ZIP downloads into `activityHook`; default partition for content images. → [documents.md](../topics/documents.md)
18. Backend look: «…» confirm buttons in list action cells; shell island in Salbei; ADR-051 point 5 palettes; count badges after insert/remove; Übersetzungen language tabs; area label in the phone top bar. → [css-backend.md](../topics/css-backend.md)
19. Contact / VAT hubs from dialogs into the edit mask; the help-button focus rule. → [fetch.md](../topics/fetch.md)
20. CE-BARE-FORM-001; navigation entry → content slug; one source for site name / brand / footer holder. → [content.md](../topics/content.md)
21. INST-OVERRIDE-ASSETS-001 go; `/login` as the backend login path and a redirect envelope for fetch. → [installer.md](../topics/installer.md), [backend.md](../topics/backend.md)
22. NAV-SEED-002 (`sort_key` hint vs. shift siblings); subnav opener summary as a link. → [navigation.md](../topics/navigation.md)
23. kibot: assistant on screens without help; `KIBOT-SOURCE-ROOT`; typed `StreamResponse`. → [kibot.md](../topics/kibot.md)
24. Packaging: further split targets; where `js-min-markers.php` runs before a deploy; source maps. → [packaging.md](../topics/packaging.md), [js-build.md](../topics/js-build.md)
25. Smaller: article group order (drag & drop vs. alphabetical); SEO-NOINDEX v2 flag vs. toggle; SMS provider (ALERT-003); foreign-currency mapping; BOOT-CONFIG-001 merge semantics; mail Phase 7 still wanted (unverified). → [article.md](../topics/article.md), [metadata.md](../topics/metadata.md), [alert.md](../topics/alert.md), [persistence-doctrine.md](../topics/persistence-doctrine.md), [bootstrap.md](../topics/bootstrap.md), [mail.md](../topics/mail.md)

---

## C — Ready to BUILD

Size: S ≈ hours, M ≈ a day or two, L ≈ several days. Top first.

| # | Item | Size | Depends on | Topic |
|---|---|---|---|---|
| 1 | Sticky totals line at the order window's bottom | S | — | [order.md](../topics/order.md) |
| 2 | Documents by e-mail: offer, order confirmation, delivery note, dunning (attachment provider per PDF + send action) | M each | mail kinds already declared | [mailing.md](../topics/mailing.md), [order.md](../topics/order.md), [debtor.md](../topics/debtor.md) |
| 3 | DEBTOR-WIN-002 — booking-print confirmation as a window; then `confirmFinalize` as a window | M | a core.js opener for GET forms | [debtor.md](../topics/debtor.md), [css-backend.md](../topics/css-backend.md) |
| 4 | SEC-002 — error handler on `DEBUG`, incl. the boot phase | S | — | [security.md](../topics/security.md) |
| 5 | INST-CONFIG-001 — classify bootstrap / moduleManager / fileFinder config | S | blocks Packagist | [installer.md](../topics/installer.md) |
| 6 | API-009 — device cookie re-issue | M | design first; due before the first device is a year old | [api.md](../topics/api.md) |
| 7 | Harness gaps: journal search checks; DB sections of 2026-10-13 (run unverified); debtor `O` / `MV` / `LC` checkpoints and customer picker; logos-from-Drive check; API-003 payload fields | S each | `z77test` credentials (PC2) | [financial.md](../topics/financial.md), [debtor.md](../topics/debtor.md), [mandator.md](../topics/mandator.md), [api.md](../topics/api.md) |
| 8 | Journal list onto the standard list (`JournalListing`, retire `JournalFilter`) | M | — | [financial.md](../topics/financial.md), [listing.md](../topics/listing.md) |
| 9 | Receipts: dashboard entry, «Journal drucken», photo zoom / rotate | S each | module registered in a project | [receipt.md](../topics/receipt.md) |
| 10 | `Request` callers: `PublicFormHandler::origin()` → `getClientIp()`, UA reads → `getUserAgent()` | XS–S | a harness way in for LoginFlow | [forms.md](../topics/forms.md), [member.md](../topics/member.md) |
| 11 | Mandant «Speichern» → primary; remove `.be-tree__actions`; ≈ 70 inline styles to classes | XS / S / M | a visual pass | [css-backend.md](../topics/css-backend.md) |
| 12 | `type_locked` marker on `account` (FIN-TYPE-001), cleared by the reopen | M | — | [financial.md](../topics/financial.md) |
| 13 | TRANS-SEED-001 compare-and-adopt in `TranslationCatalog` (decided) | M | — | [translation.md](../topics/translation.md) |
| 14 | Stats: report mail per-recipient language / month / multi-site; server-side form counter | M / S | — | [stats.md](../topics/stats.md) |
| 15 | FORM-LOG-001 — FormLog under `data/` + migration on axo3 / zihlundsee | M | — | [forms.md](../topics/forms.md) |
| 16 | Page CSP enforced | M | decision on Google Fonts | [security.md](../topics/security.md) |
| 17 | Import: upload form; streaming / chunking; v2 foreign sources + identity resolver | M / M / L | before the wdv bulk import | [import.md](../topics/import.md) |
| 18 | Content blueprints follow-ups (a)–(c) | M each | — | [content.md](../topics/content.md) |
| 19 | DMS: `SaveService` orphan cleanup, video poster on overwrite, deploy notes; ZIP Z5 Drive UI (board first); share materialization | S / S / S; L; L | — | [documents.md](../topics/documents.md) |
| 20 | P5 part 2 — VAT return, `vat-settled`, ESTV form mapping; then the debtor VAT quarter lock | L | owner decision 2026-09-30 recorded (ADR-042 addendum) | [financial.md](../topics/financial.md), [vat.md](../topics/vat.md), [debtor.md](../topics/debtor.md) |
| 21 | P7b stock journal ledger | L | — | [article.md](../topics/article.md) |
| 22 | Import seam for module validators (`AddressType`, `TaxCode` / `TaxRate`) | M | kernel `ImportServiceFactory` seam | [contact.md](../topics/contact.md), [vat.md](../topics/vat.md) |
| 23 | Correction of a POSTED receipt through the ledger | M | — | [receipt.md](../topics/receipt.md) |
| 24 | Newsletter (step 4) | L | — | [mailing.md](../topics/mailing.md) |
| 25 | v1 → v2 list migration (≈ 20 screens); full-text search (FTS5); stronger auth (magic link, passkeys) | L each | — | [css-backend.md](../topics/css-backend.md), [content.md](../topics/content.md), [security.md](../topics/security.md) |

Outside the framework: AXO3 ALERT-002 aging watch (before go-live) and API-004 emonitor adapter
([api.md](../topics/api.md), [alert.md](../topics/alert.md)).

---

## D — Known limits kept on purpose / deferred

≈ 66 bullets stay in `## pending` as deliberate limits or «later» items (request-level checks
without a controller harness, publishing / split targets, deferred features until a reader exists).
Per topic: [debtor.md](../topics/debtor.md) 6, [mandator.md](../topics/mandator.md) 4,
[article.md](../topics/article.md) 4, [documents.md](../topics/documents.md) 4,
[order.md](../topics/order.md) 3, [financial.md](../topics/financial.md) 3,
[contact.md](../topics/contact.md) 3, [entity-data-handling.md](../topics/entity-data-handling.md) 3,
[stats.md](../topics/stats.md) 2, [member.md](../topics/member.md) 2, [forms.md](../topics/forms.md) 2,
[installer.md](../topics/installer.md) 2, [css-backend.md](../topics/css-backend.md) 2,
[mail.md](../topics/mail.md) 2, [kibot.md](../topics/kibot.md) 2, [translation.md](../topics/translation.md) 2,
[metadata.md](../topics/metadata.md) 2, [cache.md](../topics/cache.md) 2, [vat.md](../topics/vat.md) 2,
[pdf.md](../topics/pdf.md) 2, [receipt.md](../topics/receipt.md) 1, [mailing.md](../topics/mailing.md) 1,
[security.md](../topics/security.md) 1, [navigation.md](../topics/navigation.md) 1,
[content.md](../topics/content.md) 1, [jobs.md](../topics/jobs.md) 1, [i18n.md](../topics/i18n.md) 1,
[routing.md](../topics/routing.md) 1, [stylesheet.md](../topics/stylesheet.md) 1,
[tree.md](../topics/tree.md) 1, [persistence-doctrine.md](../topics/persistence-doctrine.md) 1,
[listing.md](../topics/listing.md) 1.
