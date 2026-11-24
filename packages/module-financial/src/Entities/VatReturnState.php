<?php

namespace Z77\Module\Financial\Entities;

/**
 * The state of a {@see VatReturn} row (ADR-042 addendum 2026-11-24, points 1 and 5).
 *
 *   - `posted`: «MWST buchen» wrote it (with its settlement entry, or none for a nil return).
 *     Posted counts as filed — there is no second state; «Eingereicht am» is a date on the row,
 *     not a state. A posted ORDINARY return settles its period (`VatReturn\VatSettlement`).
 *   - `lifted`: an admin lifted it (a visible reversal entry, a reason in the protocol). Its
 *     figures and its voucher stay; the period is open again and the next posting writes a new
 *     row with the next revision.
 *
 * A row is created `posted` and moves once, `posted` → `lifted` (`VatReturn::lift()`). Domain
 * only, no labels.
 */
enum VatReturnState: string
{
    case Posted = 'posted';
    case Lifted = 'lifted';
}
