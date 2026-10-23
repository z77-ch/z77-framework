<?php

namespace Z77\Module\Debtor\Entities;

/**
 * The five states of an {@see Invoice} (ADR-054 decision 1, owner 2026-10-23 — the
 * Faktura-Journal; before it the two states `invoicing` / `final` of plan §6.2):
 *
 *   - `invoicing` («in Fakturierung»): a DRAFT — the snapshot exists, NO number
 *     yet (owner 2026-10-10: the number is drawn at the booking print); it
 *     can be edited as often as needed (`InvoicingService::reinvoice()`) and
 *     discarded (`InvoicingService::discard()`, owner 2026-10-13 — a real
 *     delete; it carries no number, nothing is left to correct); NOTHING is
 *     posted, there is no open amount;
 *   - `issued` («fakturiert»): numbered, printed, mailable, payable — and in the
 *     Faktura-Journal, NOT posted yet; corrected by a revision (same number) or a
 *     replacement (new number);
 *   - `final` («verbucht»): posted through the accounting port, immutable. A
 *     correction is a credit note (plan §1, correction principle) — never an
 *     edit, never a deletion. The stored value keeps its name (ADR-054
 *     decision 1);
 *   - `replaced` («ersetzt»): an issued document superseded by a successor with a
 *     new number — kept with its number and snapshot, nothing ever posted;
 *   - `deleted` («gelöscht»): an issued document deleted (its order deleted) —
 *     kept with its number and snapshot, nothing ever posted.
 *
 * One way each: `invoicing` → `issued` → `final`, `issued` → `replaced`,
 * `issued` → `deleted`. Step 1 of the build plan adds the three cases; nothing
 * PRODUCES them before step 3 — until then the booking print still goes
 * `invoicing` → `final` in one step. Readers ask the intention-revealing
 * predicates of {@see Invoice} (`isDraft()`, `isIssued()`, `isInJournal()`,
 * `isPosted()`, `isVoid()`), never a state value.
 */
enum InvoiceState: string
{
    case Invoicing = 'invoicing';
    case Issued    = 'issued';
    case Final     = 'final';
    case Replaced  = 'replaced';
    case Deleted   = 'deleted';

    /** The screen word of the state — lower case, as the badges and the hints write it (ADR-054 decision 1). */
    public function label(): string
    {
        return match ($this) {
            self::Invoicing => 'in Fakturierung',
            self::Issued    => 'fakturiert',
            self::Final     => 'verbucht',
            self::Replaced  => 'ersetzt',
            self::Deleted   => 'gelöscht',
        };
    }
}
