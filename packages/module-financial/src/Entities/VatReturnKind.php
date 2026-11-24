<?php

namespace Z77\Module\Financial\Entities;

/**
 * What a {@see VatReturn} row declares (ADR-042 addendum 2026-11-24, points 2 and 4).
 *
 *   - `ordinary`: the return of one settlement period. The only kind that SETTLES a period
 *     (`VatReturn\VatSettlement` reads no other) and the only one stage S2 writes.
 *   - `correction`: a «Korrekturabrechnung» — replaces the return filed for one period (stage S3).
 *   - `annual`: the «Jahresabstimmung» of one tax period, filed once (stage S3).
 *
 * The kind is the filing channel of a correction — there is no separate channel column. It leads
 * the unique indexes of `vat_return` from the first migration on (owner O11, 2026-11-24), so the
 * correction path only adds columns and never rebuilds an index.
 */
enum VatReturnKind: string
{
    case Ordinary   = 'ordinary';
    case Correction = 'correction';
    case Annual     = 'annual';
}
