<?php

namespace Z77\Module\Financial\Entities;

/**
 * What an {@see Account} is on the balance sheet or the income statement
 * (plan §5.1). Domain only, no labels — German display text lives in the UI
 * layer, like `ContactKind` and `TaxCategory`.
 *
 * FIVE types (owner, 2026-09-22): `equity` is its own type rather than a
 * kind of `liability`, so the balance sheet splits equity from debt by the
 * type alone — no rule about account numbers (the KMU chart's class 28) is
 * needed for it, and a chart that numbers differently still reports right.
 */
enum AccountType: string
{
    case Asset     = 'asset';
    case Liability = 'liability';
    case Equity    = 'equity';
    case Expense   = 'expense';
    case Revenue   = 'revenue';
}
