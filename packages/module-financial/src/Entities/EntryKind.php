<?php

namespace Z77\Module\Financial\Entities;

/**
 * Who wrote a {@see JournalEntry} (ADR-042 decision 7). Domain only, no labels.
 *
 *   - `generated`: posted by a module (invoicing, a payment, a reversal). Never
 *     edited, never deleted — a correction is a reversal, posted by the module
 *     that owns the source. Carries an idempotency key.
 *   - `manual`: entered in financial by the bookkeeper. Editable and deletable
 *     until the accounting close of its period, every change logged in an
 *     {@see EntryChange}. Carries no idempotency key.
 */
enum EntryKind: string
{
    case Generated = 'generated';
    case Manual    = 'manual';
}
