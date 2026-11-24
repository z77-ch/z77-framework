<?php

namespace Z77\Module\Mandator\Entities;

/**
 * When the tax arises — the second axis of the settlement method (ADR-041 addendum 2026-11-24,
 * point 1; «Abrechnungsart», Art. 39 MWSTG): by the consideration AGREED (the invoice) or
 * RECEIVED (the payment). Only `agreed` is built for the VAT return; `received` would need an
 * allocation of payments to tax summaries and makes the return refuse. Stored on {@see Mandator}
 * as its string, null = not set. Domain only, no labels.
 */
enum VatConsideration: string
{
    case Agreed   = 'agreed';
    case Received = 'received';
}
