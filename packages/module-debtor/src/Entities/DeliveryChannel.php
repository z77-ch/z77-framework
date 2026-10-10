<?php

namespace Z77\Module\Debtor\Entities;

/**
 * How a booked document reached the customer — the «Fakturiert» fact on
 * {@see Invoice} (`delivered_via`, owner 2026-10-10). Independent of the
 * posting state: a document is booked (`final`) first, delivered after.
 *
 *   - `pdf`    — set automatically the FIRST time the document's PDF is
 *                produced in the backend (the detail's «PDF», or the
 *                combined PDF of a booked batch); later PDFs change nothing;
 *   - `manual` — «Als fakturiert markieren» (sent another way: printed,
 *                handed over); the only kind that can be removed again.
 *
 * The e-mail dispatch will add its own case when it is built (`debtor.md`
 * pending) — not before (nothing on stock).
 */
enum DeliveryChannel: string
{
    case Pdf    = 'pdf';
    case Manual = 'manual';

    /** German, for the detail and the list tooltip. */
    public function label(): string
    {
        return match ($this) {
            self::Pdf    => 'PDF geöffnet',
            self::Manual => 'manuell markiert',
        };
    }
}
