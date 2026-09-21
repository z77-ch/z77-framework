<?php

namespace Z77\Module\Vat\Calculation;

/**
 * Whether the line amounts handed to the calculator are net (tax comes on top)
 * or gross (tax is contained). ADR-041 decision 5: gross mode computes
 * tax = gross × rate / (10000 + rate).
 */
enum PriceMode: string
{
    case Net   = 'net';
    case Gross = 'gross';
}
