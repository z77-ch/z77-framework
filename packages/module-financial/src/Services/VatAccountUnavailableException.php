<?php

namespace Z77\Module\Financial\Services;

/**
 * `LedgerService::vatAccountFor()` cannot resolve ANY VAT account — not
 * «this category has none» (that is a plain `null`), but an installation
 * state that has to be fixed once: `financialConfig` still carries the
 * pre-E2 key `vatAccounts` (a project override copied before the move,
 * BOOT-CONFIG-001), or the mandator record cannot be read at all (module
 * not registered, table missing — `MandatorUnavailableException`, carried
 * as `getPrevious()`).
 *
 * An `UnexpectedValueException` like every other refused setting of this
 * module (`listLimit()`), so nothing that already treats a wrong setting as
 * an error changes; `getMessage()` is GERMAN and names the next step, so the
 * journal screens and debtor's port adapter show it as a band or a refusal
 * instead of a 500 (owner decision, review 2026-09-23).
 */
final class VatAccountUnavailableException extends \UnexpectedValueException
{
    public const LEGACY_CONFIG      = 'legacy-config';
    public const MANDATOR_UNAVAILABLE = 'mandator-unavailable';

    public function __construct(public readonly string $reason, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
