# Review result — order / debtor / financial / article plan

**Date:** 2026-09-20
**Brief:** [`order-bauplan-review-request-2026-09-20.md`](order-bauplan-review-request-2026-09-20.md)
**Subject:** [`order-debtor-financial-bauplan.md`](order-debtor-financial-bauplan.md)
**How:** an independent review run by a second model (Fable) in a read-only agent with **no context
from the working session** — it had the brief, the plan, the predecessor analysis and `CLAUDE.md`, and
had to understand the plan from the documents alone. That was the point: it reads what a successor
reads, without the author sitting next to it.

## What it found

Two real model errors and one architecture error, plus eight smaller violations of the framework's own
rules. Nothing was cosmetic. The two expensive ones would both have surfaced late: one as a corrupted
stock journal that cannot be repaired, the other as a permanent synchronisation problem between two
modules.

Worth recording: the review also made one wrong assumption of its own (see Q10 below), and two of its
recommendations were **narrowed** after the developer described how the business actually runs. A
review is an input, not a verdict.

## Verdicts and what happened

| # | Question | Verdict | Outcome |
|---|---|---|---|
| R2 | flag-difference for stock movements | needs a different cut | **accepted, then refined** — see below |
| R3 | stock inside `module-article`? | wrong question; the table layout is the right one | **accepted**: service stays in article, balance/reservation/journal get their own tables keyed by variant id, so extracting a module later is a namespace move |
| R6a | invoice line lacks `type` and parent line | (not asked, found) | **accepted**: both move into P3, because they print on the document and an issued invoice is immutable afterwards |
| R6b | `invoiced` / `part-paid` / `paid` as order statuses | (not asked, found) | **accepted in substance, narrowed** — see below |
| R5 | do the §1 principles survive the migration? | the doubt was aimed at the wrong module | **accepted**: the exception is in debtor, not in the ledger; the import is now named as the one sanctioned second write path (ADR 1) |
| R1 | two-stage article | agree, with one addition | **accepted**: "every product has at least one variant, the default one is implicit and hidden while it is the only one" — without that sentence a line would reference product *or* variant |
| R4 | phase order | needs a different cut | **accepted**: own books migrate in P5b instead of P8; stock moves behind order into P7b, because the status change is its only trigger |
| R6c | attributes have no consumer in this plan | (not asked, found) | **accepted**: typed attributes, controlled values, facets, producer-as-contact and flat product URLs move to the shop concept — they would have been built "in stock" |
| R6d–h | rounding account twice, `NumberRange` three times, `override/` wording, file-vs-DB references, free-lines contradiction | various | **all accepted**, all small, all fixed in the plan |

## The two that needed more than acceptance

### Stock movements (R2) — accepted, then improved by a second objection

The review showed that computing a movement from the **difference between the old and the new status'
flags** breaks three ways: editing a flag on a status that live orders sit in makes the next
transition compute against a rewritten past; a permitted backward step un-consumes stock; and a move
to "done without stock effect" is forward-legal yet books stock back. It also forced
`module-article` to know `OrderStatus`, against the dependency direction the plan itself sets — an
error of the author's, not of the reviewer's reading.

Its alternative was adopted: the journal holds per order line what is reserved and consumed, order
passes **target quantities** rather than a status, and the service books only the difference.

Its accompanying proposal — *forbid* a transition that would un-consume stock — was **not** adopted.
The developer objected that a new status must always be introducible with its own "book / do not
book" setting, and that a mistaken click has to stay correctable. Both are right. The rule is
therefore: a reversal is **allowed but never silent** — it demands a reason and a confirmation, and
both movements stand in the journal with that reason. §1 applied literally: a counterpart, not a
deletion.

A welcome consequence: the review's requirement to **freeze the flags** of a referenced status
disappears. It was only needed while movements were computed against the previous status. Computing
against the journal keeps flags editable, which is the entire point of status being data.

### Payment state (R6b) — right diagnosis, too fast a conclusion

The review wanted `invoiced`, `part-paid` and `paid` removed from the order statuses, because they are
set from the receivables side, which makes debtor write into order and creates two truths about one
amount. The diagnosis holds.

Its conclusion "drop them, payment state is a derived display" was too fast, because these are the
most frequently used statuses in the live data. The question it skipped is *who sets them*. The
developer's answer: the receivables side does, by manual entry or through the CAMT.054 import, and a
partial booking is deliberate and matches practice.

Outcome: the statuses leave the order, but the information does not. Order **asks** debtor for the
payment state through a port, the mirror image of the `AccountingGateway` debtor uses for posting.
Nothing changes in daily use; the open item is the single truth, and no order can claim to be paid
while an open item still stands.

## Where the review was wrong

It assumed that Q10 — stock value in the bookkeeping — would eventually force stock to talk to
financial's gateway, and used that to argue for a separate stock package. The developer settled Q10
the other way: inventory valuation is a matter of judgement, the system prints a dated inventory list
and the result is entered as a **manual** journal entry. Stock therefore never posts, needs no
dependency on financial, and that argument for a separate package falls away.

## What this cost and returned

One agent run, about five minutes. It removed one feature built without evidence (attributes), moved
two phases to where mistakes surface earlier, prevented a class of unrepairable stock-journal
corruption, and turned an absolute principle into a bounded one that actually holds. Held **before**
the ADRs, as agreed on 2026-09-18 — every finding was a paragraph to rewrite rather than an ADR to
supersede.

**Next:** the five ADRs in §10 of the plan, phase P0.
