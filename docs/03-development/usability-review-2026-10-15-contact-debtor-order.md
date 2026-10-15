# Usability review — contact, debtor, order (2026-10-15)

Owner's request (2026-10-15): analyse the daily routines «capture a contact», «edit a contact»,
«make a contact a debtor» (in the contact area and in the order), «open an order with a new
customer and articles»; every routine with as few clicks as possible and intuitive; find the bugs
of finding and taking over an article (unit, text, price, VAT not taken from the article / the
product group); rebuild where necessary.

Method: walked through on z77.ch (dev server 8077) as the backend user `claude`, over HTTP with
curl in the modes core.js uses (window GET in fetch mode, multipart window POST, JSON toggle).
What curl cannot show — focus, scrolling, how the datalist looks — is reasoned from the markup and
marked as such. Test data left behind: order 4 (id 10, «Claude Test Artikel», customer
z77-test-firma, offer), contact «Claude Testkunde AG» (id 17, debtor 1007).

Click counts start on the page where the routine begins and count every click / submit, not
typing.

## 1. Result in one table

| # | Routine | Clicks today | Finding | Severity | State |
|---|---|---|---|---|---|
| D1 | Order: choose an article on a line | — | nothing fills on choice; text / unit / price / MWST / Konto only after «Speichern» | **bug** | **fixed** |
| D2 | Order: change the article of an existing line | — | text / unit / price of the OLD article stay (no longer empty → the save keeps them) | **bug** | **fixed** (with script) |
| D3 | Order: article line's MWST / Konto | — | a new row shows empty, editable free-line fields although the article decides them (D3) | confusing | **fixed** (locked fields) |
| C1 | Order: choose the customer | 1 | a plain `<select>` of every debtor and contact — no search; unusable beyond ~50 entries | high | **built** 2026-10-15 (customer picker) |
| C2 | Order: delivery address of a new customer | +1 save | the choices appear only after a save («after a customer change: save first, then choose») | medium | **built** 2026-10-15 (with the picker) |
| C3 | Order: «+ Kontakt» | 3 | works; the contact name is typed twice (contact AND address) | medium | proposal (A2) |
| C4 | Order: the total | +1 save | shown only for the SAVED order («gespeichert») — no amount while typing | medium | proposal |
| A1 | Contact: capture with «Debitor» | 3–4 | works; «Debitor» may be ticked without an address — the booking print needs one | medium | proposal |
| A2 | Contact: capture | — | name typed twice: Firma / Vorname / Name, then the address's Name / Vorname again | medium | proposal |
| B1 | Contact: make an existing contact a debtor | 4 | ⋮ → Bearbeiten → switch at the BOTTOM below a form whose «Speichern» does not concern it | medium | proposal |
| B2 | Contact list | — | no sign who is a debtor (no badge, no customer number) | medium | proposal |
| B3 | Contact list | +1 | the name is not clickable — every edit goes through ⋮ → Bearbeiten | low | proposal |
| B4 | Contact: «Debitor bearbeiten …» | — | REPLACES the contact dialog (review M4, `debtor.md` pending) — the way back is gone | low | known |

## 2. Order lines — the article bugs (D1–D3)

**What the owner saw.** Choosing an article in the «Artikel» column changed nothing on screen:
Text, Einheit and Preis stayed empty, MWST showed «–», Konto empty — so it looked as if the article
(and the product group behind it) was not taken over at all.

**What the server does — correct.** `OrderService::resolveLine()` takes, for an article line,
text / unit / price from the article when they are EMPTY (price valid at the order date, converted
to the order's price mode with the rate on the service date) and ALWAYS tax code and account from
the article (D3). Verified: order 4 with `1001` × 2 and `02.100.01` × 3, only number and quantity
posted → saved with «Beratung», h, 150.00, UN, 3400 and «ADATA SDHC 32GB», Stk., 15.40, UN, 3200;
gross mode converts 15.40 → 16.65. The product group plays no part at this point: it gives a NEW
article its default tax code and account when the ARTICLE is created (`article.md`); the order reads
the article.

**Why it looked broken.**

1. No feedback on choice — the window was built «no script» (written before Rule 7 was relaxed
   2026-09-29): the values appeared only after «Speichern».
2. A new row showed the FREE-line fields for MWST / Konto (empty, editable) — the user expects to
   fill them, and whatever was chosen there was ignored for an article line.
3. **A real bug:** changing the article of an EXISTING line kept the old article's text / unit /
   price, because they were no longer empty when the form came back.

**Rebuilt (2026-10-15).**

- `OrderService::articleDefaults(number, orderDate, serviceFrom, priceMode)` — the save's own rule
  as a read: text, unit, price, tax code, account, and the reason when there is no price.
- `article-defaults` on the order controller (GET, reads only) answers it as a fetch envelope.
- core.js «fill» — a NEW module-agnostic building block (Rule 9, `fetch.md`):
  `[data-fill-url]` on the choosing field, `[data-fill-scope]` on the row, `[data-fill-key]` on the
  fields to set; the server decides every value, the script writes `.value` only (Rule 7). Choosing
  overwrites text / unit / price (the user chose a new article) and locks MWST / Konto; clearing
  the article frees them; no price at the order date → the rest fills, an error flash asks for the
  price.
- MWST / Konto are always real fields now: on an article line the select is `disabled` and the
  account `readonly`, both showing the article's values with the title «vom Artikel».
- Without the script nothing changes against before: the save fills empty fields.

**Clicks.** Article line before: type number → «Speichern» → check → correct. Now: choose the
article → type the quantity → done; one save for the whole order.

## 3. Customer in the order (C1–C4)

- **C1 — customer select without search.** `_debtorSelect` lists every active debtor and, in a
  second group, every contact without a profile («(neu als Debitor)»). With ten entries fine; with
  hundreds a select cannot be searched (typing jumps by first letter only). **Proposal:** the same
  pattern as the article — an input with a `<datalist>` (name, customer number, place), the id
  resolved by the server; or a type-ahead fed by `ContactRepository::search()`. A shared picker
  (Rule 9) for the invoice editor and the order alike. Needs a board first (Rule 8).
- **C2 — delivery address only after a save.** `orderShipChoices()` is built from the customer the
  form was RENDERED with. **Proposal:** «fill» on the customer select answers the address choices;
  this needs one extension of the block (replacing a select's options, built as `Option` objects —
  no markup), or a small `replace-html` of the ship select from a server-rendered partial (the
  «+ Kontakt» answer already does exactly that for the customer select).
- **C3 — «+ Kontakt».** 3 clicks (open, fill, save), the new contact is selected in the order — good.
  The name is typed twice (see A2). By D9 the contact becomes a debtor at the booking print; the
  flash says so. The owner confirmed D9 on 2026-10-15 («ein Kontakt bleibt ein Kontakt, bis er
  fakturiert wird»).
- **C4 — total only after a save.** The total block is debtor's preview of the STORED order (D11 —
  no VAT arithmetic in module-order). **Proposal:** keep D11 and ask the server: a «preview» request
  for the unsaved form (the same `OrderInvoiceDraft` → `InvoicingService::preview()` without a
  write) whose answer replaces the total block. Larger; after C1.

## 4. Contact area (A1–A2, B1–B4)

**Capture** (Kontakte › «+ Kontakt»): Art · Firma · Vorname · Name · Sprache · E-Mail · Telefon ·
optional first address (Adresstyp, Bezeichnung, Anrede, Titel, Vorname, Name, Adresszusatz,
Strasse, Nr., PLZ, Ort, Land = CH) · Rollen: «Debitor» switch. Clicks: area (1) · «+ Kontakt» (1) ·
[Debitor (1)] · Speichern (1).

- **A1 — Debitor without an address.** On «Kontakt anlegen» the address is optional
  (`addressRequired: false`); with «Debitor» ticked the contact becomes a debtor that cannot be
  invoiced (the invoice snapshots an address). **Proposal:** when a role is ticked, the role may
  require an address — through the role (`ContactRoleInterface`), not hard-wired in the contact
  module (plan §2: contact knows no module).
- **A2 — the name typed twice.** The address block has its own Vorname / Name («Nachname oder
  Firma»). For the common case — the address IS the contact — the user retypes it. **Proposal:**
  `ContactForm` takes Name / Vorname of the FIRST address from the contact when the address block
  is filled but its name left empty (organisation → company, person → last name). Server side, no
  script; the field hint says «leer = wie Kontakt».

**Make an existing contact a debtor:** Kontakte › ⋮ (1) › Bearbeiten (1) › Debitor switch (1) ›
close (1) = 4 clicks.

- **B1 — two save semantics in one dialog.** The dialog's «Speichern» saves the fields; the role
  switch below acts at once («wirken sofort beim Umschalten»). It works, but the switch sits after
  the whole form and the title says «bearbeiten». **Proposal:** the «Rollen» section FIRST under
  the action row (it is what a daily user comes for), its hint unchanged.
- **B2 — no debtor sign in the list.** **Proposal:** the row shows each role that is ON as a badge
  with its summary («Debitor 1007») — generic from `ContactRoles` (`state()->on`, `summary`), so a
  later «Kreditor» appears without code in the contact module. Watch the query count: `state()`
  per row and role must be read in one query for the page (contact.md rule against N+1).
- **B3 — row click.** **Proposal:** the name opens «Bearbeiten» directly (one click less), ⋮ stays
  for the addresses.
- **B4 — «Debitor bearbeiten …» replaces the dialog.** Known (review M4); fix with the window
  stack (a window above the dialog) when the contact dialog becomes a window.

**Built 2026-10-15 (C1, C2 — owner: «die Kontaktauswahl öffnet ein Fenster zum Suchen, oder den Knopf zum Neuanlegen»):** debtor's customer picker (`debtor.md` «The customer picker»): the field «Kunde wählen …» opens a window with a standard list searched in the database (Name, Ort, Kundennr., E-Mail), «Übernehmen» per row, «+ Neuer Kontakt» in the same window; the choice fills the field and — in the order — the delivery-address select. Shared by the invoice editor and the order window. Clicks: an existing customer 2 (choose · Übernehmen), a new one 3 (choose · + Neuer Kontakt · Speichern).

## 5. Recommended order

1. **Done:** D1–D3 (article fill) — owner to re-save `core.js` in VS Code (`js-build.md`).
2. **Small, server only:** A2 (name from the contact), B2 (role badge), B3 (row click), B1 (roles
   first). One afternoon together; no new pattern.
3. **A1** — needs one method on the role interface; decide with the owner.
4. **C1 customer picker** — board first (Rule 8), then one shared picker for invoice and order.
5. **C2** delivery address without a save, **C4** live total — after C1, they share the request.

## see also

- [`../topics/order.md`](../topics/order.md) — ORDER-UI-003, the order window
- [`../topics/contact.md`](../topics/contact.md) — the contact dialog, the role registry
- [`../topics/fetch.md`](../topics/fetch.md) — core.js «fill»
- [`p7-order-plan.md`](p7-order-plan.md) — D3 (tax code / account from the article), D9 (debtor at the booking print), D11 (totals from debtor's preview)
