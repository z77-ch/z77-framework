# Review request — order / debtor / financial / article plan

**For:** the external review agreed on 2026-09-18, to be held **before** the ADRs are written.
**Subject:** [`order-debtor-financial-bauplan.md`](order-debtor-financial-bauplan.md), status
`[CONCEPT]`, nothing built yet.
**Date:** 2026-09-20

## What this review is for

The plan is about to be frozen into five ADRs (§10 there). An ADR is binding in this framework —
deviating from one requires a new ADR, not a commit. So the point of this review is to find the
decisions that will be **expensive to reverse later**, while reversing them is still free.

It is explicitly **not** a completeness check and not a style review.

## Read this much

- `order-debtor-financial-bauplan.md` — the whole thing, but §4b, §7, §9 and §1 carry the questions
  below.
- [`order-financial-review-2026-09-18.md`](order-financial-review-2026-09-18.md) — the analysis of
  the old framework that the plan rests on. Its verdict (rebuild, do not port) is settled and not up
  for review.
- The framework rules in [`CLAUDE.md`](../../CLAUDE.md), particularly CE-first (Rule 1),
  single-source config (Rule 2), and build module-agnostic (Rule 8). A finding that contradicts one
  of these is more interesting than one that does not.

**One limitation, stated up front:** the decisions in §4b were measured against the live databases of
five installations. Those measurements are **client data and are not in this repository** — they live
in the maintainer's local notes. So the numbers cannot be re-checked from here. Where a decision
hangs on a measurement, the plan says what was measured in words; treat that as reported fact, and if
a claim looks load-bearing but unverifiable, say so rather than assuming it.

## Out of scope

Settled earlier, do not re-litigate unless something downstream actually breaks:

- the module cut order / debtor / financial / vat / contact / article (D8 and §2);
- Doctrine for writes, DBAL SQL for reports, in its own package (D2, D3);
- integer minor units for money, never float (§3);
- generated entries immutable, manual entries editable until close (§1, §5.3);
- effective and agreed VAT method only, CH country pack only (D4, D5);
- subscriptions, shipping and a shop are applications on top and out of the plan (§13).

## The questions

### R1 — Is the two-stage article (product + variant) worth its cost?

**Decision (A3):** a product is the marketing unit (one page, one URL, text, images, attributes); a
variant is the stock, price and invoicing unit with its own article number. An invoice line always
references the variant. An article without variants is the normal case and then the second stage is
invisible in the UI.

**Why:** in retail with vintages, wdv makes every new vintage a new article with a new URL, so the
accumulated search rank restarts and the archived URL dies. Two of the installations have no
variants at all, which is why the second stage has to be able to disappear.

**My doubt:** it is real extra work — two entities, two screens, and every query has to know which
level it means. It is justified by one industry's pattern. If that pattern is the only driver, a
single-stage article with a "supersedes" relation might carry the same benefit for less.

### R2 — Status as data with behaviour flags, instead of a PHP enum

**Decision (Q5, §7):** `OrderStatus` is file-based master data — code, label, `level`, and flags
`reservesStock`, `consumesStock`, `invoiceable`, `invoiceInProgress`, `final`. Code asks for the
capability, never for the name. Transitions are not a matrix: forward along `level` is free, backward
is barred once an invoice is final. Projects add their own statuses under `override/`.

**Why:** measured across all installations, the status set is nearly identical, yet none uses all of
it and each adds rows of its own. A hard enum would mean a project cannot add a status without
touching the framework, which contradicts Rule 1.

**My doubt:** this trades type safety for configurability. With an enum, an impossible status is a
compile-time error; with data it is a row someone typed. The flags are the real contract, and nothing
stops a project from creating a status whose flag combination is nonsense — a status that both
reserves and consumes, or a `final` one that is still `invoiceable`. Is the flag set the right
abstraction, and does it need invariants of its own?

### R3 — Does stock belong in `module-article`?

**Decision (§4b, "Stock: one write path"):** balance and reservation sit on the variant; exactly one
service may change them, it lives in `module-article`, it knows no caller, and a trigger reaches it
as an opaque reference. Movements are journalled, the sum of movements is the balance. Correction
without an order is allowed with a mandatory reason. Stocktaking is a process and is blocked while
stock-relevant orders are unbooked.

**Why:** the balance is on the variant, so the service that owns it lives where the data is, and the
dependency direction order → article already exists.

**My doubt — the one that bothers me most.** Stock keeping with a movement journal, corrections and a
stocktake process is a domain in its own right, and I have attached it to the article module because
the number happens to sit there. That makes `module-article` carry two responsibilities: describing
what is sold, and tracking how much of it exists. A separate `module-stock` that owns the journal and
depends on article for the variant identity may be the cleaner cut — at the price of one more package
for a feature two installations use and one switches off entirely.

### R4 — Phase order: dependencies or visible use?

**Decision (§9):** P1 money/persistence/vat/contact → P2–P5 financial and debtor → P6 article →
P7 order → P8 migration.

**Why:** contact is needed by debtor, bookkeeping must exist before an invoice can post, and an order
line resolves to a variant, so article precedes order.

**My doubt:** this is sorted by dependency, not by when the developer holds something useful. Manual
bookkeeping arrives in P2, but the daily work — quote, order, invoice — only lands in P7, which is
late for a solo developer who has to keep earning while building. A thinner order earlier, with free
positions and no article master, might change the risk profile considerably.

### R5 — Are the two principles in §1 actually sustainable?

**Decisions:** *correction principle* — nothing is corrected by deletion or by a special state, only
by a counterpart of the same shape (reversal, credit note, order with negative quantities); *one
write path* — every stock quantity has exactly one service allowed to change it and every change is
journalled.

**My doubt:** both are stated absolutely. Migration (§8) imports history and must write balances and
entries that no status change produced. Does the seam survive that, or does the import become the
first exception — and does one exception make the rule decorative? If an exception is unavoidable,
better to name it in the ADR than to discover it in P8.

### R6 — What did I miss?

Anything load-bearing that is wrong or absent and is **not** covered above. Specifically welcome:

- a place where the plan violates its own rules in CLAUDE.md without saying so;
- a decision that looks small here but will be expensive in P8 (the migration);
- a feature built "in stock" that no measurement supports — the guiding rule is *nothing twice,
  nothing in stock*, and I have already had to remove one such feature (a cancelled status) during
  this round.

## What I need back

Per question: **agree / disagree / needs a different cut**, with the reasoning in a few sentences —
and where you disagree, the alternative you would choose and what it costs. Ranked by cost of getting
it wrong, not by confidence.

If a question turns out to be the wrong question, say that instead of answering it.
