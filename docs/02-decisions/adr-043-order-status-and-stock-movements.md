# ADR-043 — Order status as data, and the stock movements it controls

**Status:** `[APPROVED]` — approved by the owner 2026-09-21 (P0 of [`order-debtor-financial-bauplan.md`](../03-development/order-debtor-financial-bauplan.md)); addendum 2026-10-14 (P7 slim seed, `invoiceBooked` / `offer` / `deliveryNoteTarget`, position changes trigger stock — approved by the owner); addendum 2026-10-15 (fixed phases carry the behaviour, statuses are labels, a cancelled phase — approved by the owner); addendum 2026-10-18 (the debtor carries the price mode, a price-mode switch re-converts the rows, no document for a contact without a debtor profile — approved by the owner)
**Date:** 2026-09-21

---

## Context

In wdv-6.2.2 the order status is a fixed set, and code branches on status names. Stock balances are
changed from many places and nothing records how a balance came about, so a wrong balance can be
corrected but never explained. The receivables side writes `invoiced`, `part-paid` and `paid` into the
order. A `cancelled` status exists and is not used in practice.

Measured across the installations (plan §7): the status set is almost the same everywhere, yet no
installation uses all of it and each adds rows of its own. That rules out an enum.

This is one ADR because the status **is** the stock control: a stock movement has no other trigger.
The design was reviewed on 2026-09-20; the review's objections are worked in (plan §4b,
[`order-bauplan-review-result-2026-09-20.md`](../03-development/order-bauplan-review-result-2026-09-20.md)).

## Decision

### Status

1. **Status is data, not an enum.** `OrderStatus` is file-based installation master data in `data/`
   (seed-once, ADR-024), maintained in the backend: `code`, multilingual `label`, `level` (a progress
   number), and the flags code reacts to — `reservesStock`, `consumesStock`, `invoiceable`,
   `invoiceInProgress`, `final`. Code never asks «is the status X», it asks «does this status consume
   stock». Seed: offer, reserved, ordered, delivered, to be invoiced, in invoicing, done, done without
   stock effect. *(Amended — seed, `invoiceBooked` instead of `invoiceInProgress`, `offer`, `deliveryNoteTarget`, German label: addendum 2026-10-14.)*
2. **Invariants per status**, checked when a status is saved:
   - `final ⇒ ¬invoiceable ∧ ¬invoiceInProgress`
   - `consumesStock ⇒ ¬reservesStock`
   - a flag by which code *selects* a status (e.g. the status set when invoicing starts) is unique per
     installation
   - a status referenced by any order is deactivated, never deleted
3. **Flags stay editable**, also on a status live orders sit in. Movements are computed against the
   journal (below), so a flag change cannot corrupt history. That freedom is the reason status is data
   at all.
4. **Two transition rules, no matrix.** Forward along `level` is free. Backward is barred once an
   invoice for the order is `final` — the bar comes from the invoice, not from the status model.
   Every change is logged (who, when, from → to); the timestamps of offer, confirmation, delivery and
   invoicing are derived from that log, not kept in parallel columns.
5. **No cancelled status.** Before invoicing, a cancelled order goes to «done without stock effect»;
   after invoicing, a credit note is issued and cleared in receivables. A third answer would hide which
   of the two happened.
6. **Payment state is not an order status** (ADR-040): it is asked from debtor.
7. **A return is an order with negative quantities**, created from the original at one click. Lines
   therefore allow negative quantities.
8. **Status is per order, consumption is per line.** Lines move together; a partial delivery is an
   **order split**. Moving status to line level later is a schema change on the movement journal.

### Stock

9. **One service owns the balance** — in `module-article`, as long as nothing else is built on stock.
   No controller, repository, import, form or run touches balance or reservation directly. It knows no
   caller; the trigger arrives as an opaque reference, like `LedgerService::post()`.
10. **Own tables keyed by variant id** for balance, reservation and movement journal — not columns on
    the variant — so a later `module-stock` is a namespace move.
11. **The status change is the only trigger.** order turns the target status' flags into **target
    quantities** per line (`reservesStock` → reserved = line quantity, `consumesStock` → consumed = line
    quantity, neither → zero) and asks the service to bring the line there. It passes quantities, never
    a status, so article never knows `OrderStatus`. *(Amended — position changes trigger too, the quantities still from the flags: addendum 2026-10-14.)*
12. **The movement is the difference against the journal**, never against the previous status. The
    journal knows per order line what is reserved and consumed; the service books only the difference.
    A repeated call finds no difference and books nothing — idempotency by construction.
13. **Status change and movement commit in one transaction** (ADR-039, ADR-040).
14. **A reversal is allowed but never silent.** A change that would bring consumed stock back into the
    balance — a mistaken «delivered», or «done without stock effect» after delivery — demands a
    **reason and a confirmation**; both movements stand in the journal with that reason. It is not
    forbidden: a mistaken click has to stay correctable, and a new status must always be introducible
    with its own stock setting.
15. **Every movement is journalled** — variant, quantity, direction, trigger (order and status change
    from → to, or a correction reason), time, who. The sum of the movements **is** the balance.
16. **Correction without an order** is the one other way in: the same service, a mandatory reason
    (stocktake, shrinkage, breakage, correction, opening balance) and a free text.
17. **Stocktaking is a process:** count list with a timestamp → count → enter → differences → release
    → book. Expected quantity at count time = current balance − movements after the count time. It is
    **blocked while stock-relevant orders are unbooked**, through the open-work check registry
    (ADR-039).
18. **Stock never posts.** Inventory valuation is judgement (Q10); the system prints a dated balance
    per variant, and the bookkeeper enters the result as a manual journal entry. article needs no
    dependency on financial.

### Referencing file-based master data from database rows

19. Database rows reference file-based master data — `OrderStatus`, `PaymentTerms`, `DunningLevel`,
    `PaymentTarget`, `TaxCode`, `AddressType` — **by `code`**, **snapshot** what they show at the moment
    of use, and the referenced rows are **deactivated, never deleted**. There is no foreign key across
    the two drivers, and a database restore is not paired with a `data/` restore; this rule is the
    replacement for the missing constraint.

## Reasoning

- **Flags instead of names** let an installation add «awaiting pick-up» with its own stock behaviour
  without a line of code — which is exactly what the measured installations do today.
- **Computing against the journal** was the review's decisive correction: against the previous status,
  an edited flag, a permitted backward step or a forward move to a no-stock status would each book
  wrongly, and the journal could not be repaired afterwards.
- **Quantities, not statuses, cross the module boundary**, which keeps the dependency direction of
  ADR-040 intact.
- **Allowing reversal with a reason** instead of forbidding it keeps the system usable on a bad day and
  still honest: the journal shows what happened and why.
- **No cancelled state and no payment states** follow from the correction principle and from «one
  truth per amount».
- **The reference rule** has to be explicit because the two storages cannot enforce it: a deleted tax
  code or status would leave rows in the database pointing at nothing, and a restore of one side only
  would do the same.

## Consequences

- order code contains no status name. A test asserts that no code path selects a status by `code`.
- The seed statuses are proven in wdv; each installation adapts them in the backend.
- Stock is built in P7b, after order, because the status change is its only trigger (plan §9).
- The stocktake shows the unbooked orders before counting begins.
- Migration: stock opening balances go in as corrections with reason `opening balance` — no exception to
  the one write path (ADR-040).
- ADR-041 refers to decision 19 for tax codes.

## Rejected Alternatives

| Option | Why rejected |
|---|---|
| Status as PHP enum | Every installation adds its own rows; an enum would force code changes for data |
| Movement computed from the old and new status' flags (first draft) | Edited flags, backward steps and moves to no-stock statuses corrupt the journal irreparably (review 2026-09-20) |
| Freeze the flags of a referenced status | Needed only for the rejected computation; removes the reason status is data |
| Forbid transitions that un-consume stock | A mistaken click could not be corrected; a new status could not be introduced freely |
| A transition matrix | Unmaintainable per installation; two rules cover the real cases |
| `cancelled` status | Unused in practice; hides whether the order was stopped before or after invoicing |
| `invoiced` / `part-paid` / `paid` as statuses | Two truths about one amount; debtor would write into order (ADR-040) |
| Balance as columns on the variant | Extraction of a stock module becomes a data migration instead of a namespace move |
| Stock posts its value to the ledger | Valuation is judgement (Q10); it would couple article to financial |
| Foreign keys from rows to file-based master data | Impossible across two drivers |

## Addendum 2026-10-14 — the P7 slim seed, position changes as trigger, German labels (approved by the owner)

Approved by the owner on 2026-10-14 («Alles ok los») with the P7 slim build plan
([`p7-order-plan.md`](../03-development/p7-order-plan.md) §2, §4.2, §15). It amends decisions 1 and 11;
their original text above stays as written, and where it and this addendum differ, the addendum
applies.

1. **Decision 1 — the seed and the flags.** The seed is Offerte → Terminauftrag → Geliefert → Zu
   fakturieren → Gebucht → Erledigt mit Lagerbuchung / Erledigt ohne Lagerbuchung (codes `offer`,
   `scheduled`, `delivered`, `to-invoice`, `booked`, `done`, `done-no-stock`). `invoiceInProgress` is
   replaced by **`invoiceBooked`** — there is no invoice draft stage for orders (ADR-040 addendum
   2026-10-14); two flags are added: **`offer`** (the order is a quote) and **`deliveryNoteTarget`**
   (the status the delivery-note print sets). `invoiceBooked` and `deliveryNoteTarget` are flags by
   which code SELECTS a status, so each is unique among the active rows (decision 2). The extended
   invariants are listed in the plan, §4.2.
2. **Decision 11 — the trigger.** «The status change is the only trigger» becomes **«the order's
   writes are the only trigger — status change AND position change (add / edit / delete); the
   quantities still follow from the status flags only»** (owner decision O5). Order sends the
   COMPLETE target state of the document every time through ONE method
   (`StockLedger::bringTo()`, module-article); decision 12 (the difference against the journal)
   makes that safe and idempotent. Quantities, never a status, cross the boundary.
3. **Decision 1 — the label.** The label stays GERMAN like every master-data label; a customer-facing,
   multilingual label comes with its first consumer (a shop, a customer portal).

Also approved the same day (owner, plan §15 item 5): **tax code and revenue account sit on the
ARTICLE** (P6 slim); when the product-group tree comes, it only supplies DEFAULTS for new articles.

## Addendum 2026-10-15 — fixed PHASES carry the behaviour, statuses are labels (approved by the owner)

Decided by the owner on 2026-10-15 in the review of the status screen («ist irgendwie irreführend,
wenn der Schalter Offerte es steuert — warum hat es noch reserviert, verbraucht, fakturierbar?»;
«die Schwachstelle ist eigentlich nur der Mensch, der den Status verändert»; «ja passt los»). It
amends decisions 1, 2, 3, 4 and 5 and the addendum of 2026-10-14 (1); their text above stays as
written, and where it and this addendum differ, this addendum applies. Stock decisions 9–18 are
unchanged.

1. **Two things, apart.** The **phase** is FIXED in code and carries every behaviour; the **status**
   is installation data — a label to order and find orders by («alle Terminaufträge», «alle
   Bestellungen»), any number of them, each in exactly ONE phase. The phase is what wdv-630 called
   the order state's *level*. The seven behaviour flags (`offer`, `reservesStock`, `consumesStock`,
   `invoiceable`, `invoiceBooked`, `deliveryNoteTarget`, `final`) are REMOVED — most of their 128
   combinations were invalid, and a human who set one wrongly booked stock wrongly.
2. **The phases** (`OrderPhase`, in this order — the ladder):

   | Phase | Stock target of a line | Booking print | Documents |
   |---|---|---|---|
   | `new` | nothing | no | «Offerte»; the order confirmation MOVES to `confirmed` |
   | `confirmed` | reserved | yes | «Auftragsbestätigung»; the delivery note MOVES to `delivered` |
   | `delivered` | consumed | yes | «Auftragsbestätigung», delivery note (reprint) |
   | `closed` | consumed | — locked | — |
   | `cancelled` | nothing | — locked | — |

   «Bereitgestellt» and «geliefert» are both `delivered` — the goods left the shelf; where they stand
   is the status (owner). «Zu fakturieren» is a status of `delivered`. Whether a LINE moves stock at
   all is the ARTICLE's (`stock_relevant`, decided per variant by the stock ledger — `article.md`) — a service or a free line never does,
   in any phase; no status says «without stock».
3. **A status** has: `code` (immutable, the reference rule of decision 19), German `label`, `phase`,
   `position` (the order within its phase), `phaseDefault`, `active`. Nothing else.
4. **The default status of a phase.** At most ONE active status per phase is its default. An ACTION
   that moves an order INTO another phase sets that phase's default: a new order starts in the default
   of `new` (the form may pick any active status of `new`, `confirmed` or `delivered`); the order
   confirmation print sets the default of `confirmed`; the delivery-note print the default of
   `delivered`; the booking print the default of `closed` («Gebucht»). An action that finds the order
   already in or past its phase moves nothing (a reprint). Without a marked default, the first active
   status of the phase (by position) is taken.
5. **Transitions** (decision 4, restated by phase): a status change by hand stays («Status ändern …»).
   Forward or lateral (a phase at or after the current one) is free; backward only while the order has
   NO invoice; the default of `closed` is reached ONLY by the booking print (no «gebucht» without an
   invoice); `cancelled` only without an invoice (after invoicing, a credit note — decision 5's reason
   stands for that case). Stock follows from the target state alone (decision 12): skipping a phase
   books the end state directly, going back from consumed asks for a reason.
6. **Decision 5 is lifted for the time before invoicing**: there IS a cancelled phase. «Erledigt»
   means delivered and done (stock consumed); «storniert» means the order does not happen (stock
   released). Folding both into «done without stock effect» needed a stock flag on a status — the
   very configuration this addendum removes.
7. **Derived, not configured:** the order list's tabs are the phases (Offerten · In Arbeit ·
   Geliefert · Abgeschlossen · Storniert · Alle); an order is editable while it has no invoice and its
   phase is not `closed` / `cancelled` (plan D7); the year-close warning covers `confirmed` and
   `delivered` (every bookable order — a service order may never see a delivery note).
8. **Later, only on demand:** a per-status «this action sets …» override, restricted by the server to
   statuses of the action's phase. Not built.

## Addendum 2026-10-16 — phase names, one start status, switches that take over (approved by the owner)

From the owner's browser test of the phases. Amends decision 4 and 7 of the addendum 2026-10-15;
where they differ, this addendum applies. The phases, their behaviour and the transitions stay.

1. **Names.** The phases read **Eröffnet** (`new`, was «Neu / Offerte»), **Termin** (`confirmed`,
   was «Bestätigt»), Geliefert, Abgeschlossen, Storniert; the order list's tabs follow: Eröffnet ·
   Termin · Geliefert · Abgeschlossen · Storniert · Alle. The enum values stay — a name is a label.
   The quote's PDF still says «Offerte».
2. **ONE start status for all** (`OrderStatus::start`), in a phase that allows it — Eröffnet or
   Termin (`OrderPhase::allowsStart()`; a service order may start as «Terminauftrag»). It replaces
   «the default of `new`» as the start of a new order; `new` has no action default any more. Without
   an active start status a new order takes the first active status of `new`.
3. **Action defaults per phase** as before for the order confirmation (Termin), the delivery note
   (Geliefert) and the booking print (Abgeschlossen) — a status in Termin may carry both switches.
4. **Setting a switch takes it over** (`OrderStatuses::takeovers()`): the start status from every
   other row, an action default from the other rows of its phase — in the same save, no refusal (was:
   «a second active default is refused»). Reactivating a row takes nothing: it drops the switch
   another row holds meanwhile. A switch its phase does not know reads as off and is not stored.
5. A status file of 2026-10-15 (no `start` key) reads its `new` default as the start status — no
   hand conversion.
6. **The seed's first code is `opened`** (was `offer` — a code names the phase it starts, not a
   document; owner 2026-10-16). Only z77.ch ran the module: its file and its orders were renamed by
   hand the same day; no migration ships (the module was not released).

## Addendum 2026-10-16 — the order window: billing address snapshot, free delivery address, every priced line an article (approved by the owner)

Decided by the owner on 2026-10-16 with the redesign of the order window (`order.md` pending
«Order window redesign»). It amends plan D2 and D3 ([`p7-order-plan.md`](../03-development/p7-order-plan.md))
and the delivery-address choice of the order form; where they differ, this addendum applies. The
phases, the statuses, the transitions and the stock decisions stay. **Decided, NOT built.**

1. **The billing address is a SNAPSHOT on the order** — `bill_*`, debtor's `AddressSnapshot`
   embeddable on `SalesOrder`, exactly like `ship_*`. Reason: an address change at the customer must
   not change an existing order; the order's PDFs (Offerte, Auftragsbestätigung, Lieferschein,
   invoice preview) are generated from the order, so the order must hold the address it prints. The
   booked invoice takes it over into its `addr_*` snapshot (`Invoice`): `OrderInvoiceDraft::of()`
   passes it as `InvoiceDraft::$address` — the seam debtor already offers for «a source that already
   holds the address» (`InvoicingService` takes `$draft->address` before `invoiceAddressOf()`).
2. **The delivery address is freely adjustable per order.** The choices of the customer's addresses
   stay as a start; the snapshot may then be edited, or entered freely. The change applies to THIS
   order only — the customer's `ContactAddress` rows stay as they are. Reason: a delivery to a
   one-off place (an event, a neighbour) is an order fact, not customer master data. Today
   `OrderForm::toDraft()` refuses any `ship` value outside `orderShipChoices()` («Diese Lieferadresse
   gehört nicht zum gewählten Kunden»); that refusal goes. The same applies to `bill_*`.
3. **No free priced lines.** Reverses D2 («the article reference is NULLABLE — a free line») for
   PRICED lines: every service or lump-sum line references an article (variant); a lump sum is an
   article too. Text lines stay and never reference an article. Reason: the article is the
   accounting classification (A1, D3) — a free priced line was the one place where a person entering
   an order picked a tax code and a revenue account, and the one line kind the stock seam could not
   see. D3's «a different account is a free line» becomes «a different account is a different
   article».
4. **No revenue account (Konto) in the order window.** The article decides it (D3); it is
   irrelevant to the person entering the order. `sales_order_line.revenue_account` stays — the
   snapshot taken from the article at save, which the invoice line receives.
5. **VAT shown as the rate** («8.1 %», not the tax code), from the article, not changeable in the
   order. The rate is the article's tax code at the order's service date (`VatRates`, as the draft
   already converts prices); `sales_order_line.tax_code` stays the stored reference.

**Open — NOT decided:**

- ~~What happens to EXISTING orders that hold free priced lines~~ — **decided 2026-10-18 (owner:
  «ok»)**: they stay readable, savable and bookable as long as those lines are UNCHANGED; see the
  addendum 2026-10-18 «positions» below.
- What existing orders get as `bill_*` (they have none): filled once from the customer's current
  invoice address by a data migration, or left empty with «empty = debtor snapshots the contact's
  invoice address at booking» (today's behaviour) as the fallback.

**Consequences — to build:**

- **Schema migration** (schema only, debtor's rule — a data step, if the open point asks for one,
  apart): `sales_order.bill_*` with the `AddressSnapshot` columns (salutation, title, first name,
  name, address row, street, house no, zip, city, country), like `ship_*`.
- **`SalesOrder`**: the embedded `bill` snapshot, its getter, and `describe()` taking it; the
  draft (`OrderDraft`) carries it; a customer change resets both snapshots to the new customer's
  addresses.
- **`OrderInvoiceDraft::of()`**: `address:` the order's billing snapshot instead of `null`; the
  class doc changes accordingly. `OrderPdf` takes the recipient from `bill_*` (and the delivery
  note's fallback from `bill_*`, not from the contact).
- **`OrderForm::toDraft()` / `orderShipChoices()`**: the selects become starting points; the
  submitted snapshot fields are taken and validated as an address, not checked against the
  customer's list.
- **Validator** (`OrderService`, the priced-line branch): a service or lump-sum line without
  `variantId` is refused; `OrderLineDraft::free()` and the free line's tax-code / account checks
  go; the window loses the tax-code and account inputs and shows the rate read-only.
- **Harness `tests/module-order.php`**: D — the invoice draft carries the order's billing snapshot;
  F — a delivery address outside the customer's list is accepted, a priced line without article is
  refused, no tax-code / account field is read; M — `bill_*` round-trips, a later address change at
  the contact leaves the order and its PDF unchanged, the booked invoice's `addr_*` equals `bill_*`.
  The existing checks that create free lines (T11 fixture, F3, M `o1`) are rewritten.

## Addendum 2026-10-18 — the order's payment terms; the payment target leaves the window (approved by the owner)

Decided by the owner on 2026-10-18; **built the same day**. Where the order window and plan §9.4
differ, this addendum applies. «Zahlungsziel» keeps its meaning in z77 — the BANK account of the
payment part (debtor's `PaymentTarget`); the debtor screens and master data are unchanged.

1. **Block (3) of the order window offers «Zahlungskonditionen»** — debtor's `PaymentTerms` (the
   days, «30 Tage netto»), stored BY CODE in `sales_order.payment_terms_code` (nullable, like
   `payment_target_code`; migration `Version20261018100000`, schema only). NULL / «wie beim
   Kunden» = the customer's debtor terms at the booking print — what every order did before, so
   existing orders need no data step. `OrderService` checks the code like the payment target
   (unknown → refused on `payment_terms_code`; inactive only when the order already holds it);
   `OrderInvoiceDraft::of()` passes it as `InvoiceDraft::$paymentTermsCode` — debtor applies it to
   the preview and the booked invoice (`InvoicingService::compose()`: the draft's code before the
   profile's). A new order preselects a preset customer's profile terms, else the terms a new
   debtor gets (`defaultForNewDebtor()`); «Kunde wechseln» resets the select to «wie beim Kunden».
2. **«Debitor anpassen»** beside the label opens debtor's own profile dialog
   (`DebtorProfileLayout::BASE/edit`) over the window — the customer's standing terms are changed
   there, not in the order. No link without a customer or for a contact without a debtor profile
   (debtor opens a profile only in the contact editor or with the first invoice / booking print).
3. **The payment target is no longer a field of the order window.** `sales_order.payment_target_code`
   stays and the booking print still prints the payment part for it; the SERVER sets it — a new
   order the first active target (`orderDefaultTarget()`, the former preselection), an existing
   order keeps its own. A posted `target` is ignored (`OrderForm::withTarget()`).

## Addendum 2026-10-18 — positions: no lump sum, every priced line an article, position as sort key (approved by the owner)

Decided by the owner on 2026-10-18 with the positions block of the order window (the draft
`order-mask-draft.html`); **built the same day**. It completes the addendum 2026-10-16 points 3–5;
where plan D2 or the window differ, this addendum applies. Debtor's own invoice editor and
`LineType` are unchanged — the invoice keeps «Pauschale».

1. **No «Pauschale» in an order.** An order knows two line types: «Leistung» (`service`) and «Text»
   (`text`). A lump sum is an ARTICLE with quantity 1. `OrderService` refuses the type `lump-sum`
   (`lines.{path}.type`), `OrderForm::TYPES` offers only the two. **Existing lump-sum lines**: they
   read and invoice as before while nobody saves the order (`OrderInvoiceDraft` maps them 1:1, the
   stock counts them as one unit); the window shows them as «Leistung» × 1 — the same amount — and
   a save writes them as `service` with quantity 1.000 (an existing FREE lump sum counts as
   unchanged under point 2).
2. **Every priced line is an article** (point 3 of the addendum 2026-10-16, now enforced). A new
   priced line without `variantId` is refused (`lines.{path}.variant` — «Artikel wählen»). An
   EXISTING free priced line (`variant_id` null on a non-text line, made before) stays readable,
   savable and bookable as long as it is UNCHANGED: sent back with its `id`, with the same text,
   quantity, unit, unit price and discount (a stored lump sum reads as quantity 1) — it keeps its
   stored tax code and revenue account. Any change to it is refused («nur unverändert speicherbar —
   für eine Änderung einen Artikel wählen»); moving or indenting it is no change. The rule lives in
   `OrderService::resolveLine()` (`freeLineUnchanged()`) — the service owns rules, the form only
   maps. `OrderLineDraft::free()` and the draft's `taxCode` / `revenueAccount` are gone, so is the
   free line's tax-code / account check (`judgeFreeLine()`); the window has no Konto column and no
   tax-code input — the MWST column shows the article's RATE, read-only (point 5).
3. **The position is a SORT KEY; indentation is a FLAG.** The window's rows are ordered by drag &
   drop (⋮⋮, keyboard Alt+↑ / Alt+↓) and posted in that order; `apply()` writes `position`
   1, 2, 3 … in posted order as before. The indent toggle (⇥) is the row's `child` flag: an indented
   row stays the CHILD of the nearest preceding non-indented row (`parent_line_id`, one level —
   unchanged), so the PDFs, the invoice mapping and the stock are untouched. Dragging a main row
   moves its indented rows with it. Without the script the order is the rendered one and the flag
   is a checkbox.

**Consequences (built):** `OrderService` (type and article rule), `OrderLineDraft` (no tax code /
account), `OrderForm` (two types, `applyOp()` for `add-article` / `add-text` / `remove:{i}`, a
stored lump sum shown as service × 1, no empty row on an existing order), the window
(`edit.tpl.php`, core.js «rows» / «autosize» / «line sums»), harness `tests/module-order.php` F3,
F7–F9, M6–M9, M19, M21; `tests/module-debtor.php` O (its order line is an article now).

## Addendum 2026-10-18 — the debtor carries the price mode; no document for a contact without a debtor profile (approved by the owner)

Decided by the owner on 2026-10-18 after the bug report of the same day (article 02.100.02, 16.65
GROSS, showed 15.40 in an order «whatever its Preise setting»; the row price was filled once under
«netto» and a later switch to «brutto» kept the number — the customer would have been billed 15.40
incl. VAT); **built the same day**. Where plan D9 / Q4, the addendum 2026-10-18 «payment terms»
point 2 and debtor's DEBTOR-CONTACT-001 differ, this addendum applies to the order window and
debtor's invoice editor.

1. **The debtor carries the price mode.** `debtor_profile.price_mode` `net` | `gross` (module-vat's
   `PriceMode`; module-debtor migration `Version20261018130000`, schema only — the column default
   `net` is what every existing profile and every order so far started with). Edited in debtor's
   profile dialog («Debitor bearbeiten» / «Debitor anpassen»), asked in «Debitor anlegen» (point 4);
   the contact editor's role «Debitor» opens with `net` and shows it in its summary. A NEW order or
   invoice with a preset customer starts with the debtor's mode; choosing a customer sets the
   document's «Preise» from the debtor (debtor's shared picker answer, core.js `set-value`).
2. **The document's «Preise» stays changeable** per order / invoice — the debtor's mode is the
   preset, the document's `price_mode` remains the truth of what it was priced in.
3. **A price-mode change re-converts the order's rows at once, in the window, without a question**
   (the select, or a customer choice that changes the mode while rows exist — core.js «price
   mode»): an article row whose price equals the article's price in the old mode takes the
   article's EXACT price in the new mode (16.65 gross, not 15.40 × 1.081); any other price (typed
   by hand, an existing free line) is converted with the row's VAT rate — in cents, r = the rate in
   hundredths of a percent, **rounded half away from zero** (`Money::multiplyByRatio()`, the rule
   `articlePrice()` already used): net → gross = c × (10000 + r) / 10000, gross → net =
   c × 10000 / (10000 + r); a row without a rate keeps its price. The live totals follow.
   **Server safety net (Rule 7):** the form carries `prices_mode` — the mode its prices are in,
   rendered as the order's mode and rewritten by the script only when a conversion is complete;
   `OrderService` converts the posted prices by the same rule whenever `prices_mode` differs from
   `price_mode` (no script, a stale client) — a price is never stored under the other meaning. A
   `prices_mode` that names no mode is refused. A kept free line counts as unchanged when its price
   equals the stored price converted into the new mode. The window's rate is the one on the ORDER
   date, the server's on the SERVICE date — they differ only across a VAT rate change; then the
   server's conversion of a script-converted free line may refuse it as changed (edge, accepted).
   The invoice editor takes the debtor's mode but does NOT re-convert typed prices (it has no
   article prices and no rate per row on the page) — its help says: choose the customer first.
4. **No document for a contact without a debtor profile.** When the customer picker (shared by the
   order window and the invoice editor) is answered with a contact that has no profile, or «+ Kontakt»
   saves a new one, the window asks «<Name> ist noch kein Debitor — Debitor anlegen» (price mode,
   payment terms preset with `defaultForNewDebtor()`, the customer number drawn on «Debitor
   anlegen»); only the created debtor becomes the customer, «Abbrechen» chooses none. Reason
   (owner): otherwise a net invoice goes out that must be booked, credited and redone. The profile
   is opened through ONE path, `DebtorProfileService::open()` (the validator, the customer number
   in its own unit of work) — also used by the role «Debitor» and `openDebtorProfile()`. The saves
   re-check it (`CustomerPicker::debtorRefusal()`): the order window refuses a customer NEW to the
   order without a profile, the invoice editor refuses it and no longer saves with
   `openProfile: true`. **Kept:** an existing order keeps a profile-less customer it already holds
   (older drafts), and the booking print still opens a missing profile (plan D9,
   `openDebtorProfile()`); `OrderService` itself still accepts a prospect (imports and later
   sources decide for themselves) — the rule is the screens'.

**Consequences (built):** module-debtor `DebtorProfile::$priceMode`, `DebtorProfileValidator::
validatePriceMode()`, `DebtorProfileService::open()`, the profile dialog's «Preise», the picker's
`customerDebtorAction()` / `customerDebtorPage()` and template `Backend/CustomerPicker/debtor`, the
`set-value` of `price_mode` in `customerChosenAnswer()`, `CustomerPicker::debtorRefusal()`, the
invoice editor (start mode, refusal, «+ Kontakt»); module-order `OrderDraft::$pricesMode`,
`OrderService::repriced()` / `convertByRate()` / `storedPriceIn()`, `articleDefaults()['prices']`,
`OrderForm` `prices_mode`, the window (`data-price-mode`, hidden `prices_mode`, start mode,
refusal, «+ Kontakt»); core.js «price mode», `fill.ask()`, command `set-value`; harness
`tests/module-order.php` F10–F12, M22–M26, `tests/module-debtor.php` A11/A12c, V7–V9, W13,
`tests/js-min-markers.php`.

**Note 2026-10-19 (owner, after the browser test of the order window; built the same day).** Two
points of this addendum change:

- **Point 3 — the invoice editor converts too.** Its «Preise» re-converts typed prices at once like
  the order window (core.js «price mode»; each row's MWST select carries the rate on the service
  date, core.js «dated rates» keeps it current). The invoice has no article prices: every price
  converts with its row's rate. The ONE rule is module-vat's `VatCalculator::convertPrice()`
  (half away from zero) — the order and the invoice editor use it, core.js does the same in the
  browser. Server safety net as for the order: the editor posts `prices_mode`; when it names the
  other mode, `InvoiceForm::toDraft()` converts. The sentence «the invoice editor … does NOT
  re-convert typed prices» above no longer holds.
- **Point 4 — the rule moves into the services.** `OrderService` (a new order, a customer change)
  and `InvoicingService` (every new document — `invoice()`'s `$openProfile` is removed) refuse a
  contact without a debtor profile («… ist noch kein Debitor — zuerst als Debitor anlegen.»,
  `DebtorProfileService::notDebtor()`); the windows' own re-checks are gone (one message). The
  sentence «`OrderService` itself still accepts a prospect … — the rule is the screens'» no longer
  holds. **Integration rule:** an importer or a shop creates the contact AND the debtor FIRST —
  the debtor only through `DebtorProfileService::open()` — then the document. **Kept, and the only
  remaining place** that treats a contact without a profile as a customer: the booking print of an
  OLD order (plan D9 — `preview(openProfile: true)` composes, `openDebtorProfile()` opens the
  profile before the booking). An existing order keeps the customer it holds.

## Addendum 2026-10-20 — the status as a field of the order window

Owner decision 2026-10-20: for an EDITABLE order the status is a select in the window's action row
(the current status + the allowed targets) and moves with «Speichern» — `OrderService::update()`
takes an optional `moveTo` and moves the order in the SAME unit of work as the rewrite, under the
rule of `changeStatus()` (refusal, log row `status`, the stock brought to the new phase in one
call). A refusal or a missing reason saves nothing. Rationale: a move on change would bump the
version and make the next save of unsaved edits fail as a conflict. A locked order keeps «Status
ändern …» (point 5 above stands for it).

**Same day, second owner decision:** with a booked invoice the status does NOT move by hand at all
(`OrderStatuses::INVOICED` replaces `BACKWARD_BARRED` / `CANCEL_BARRED`). Owner: an invoice is not
changeable, it carries bookings, it is resolved only by a credit note and a write-off — «Erledigt»
is for an order that is never invoiced (a total-0 order, owner's view). This narrows point 5 and
Q6: «Erledigt» by hand stays, but only without an invoice. The order window shows the status of an
invoiced order as a plain field (no link, no window).
