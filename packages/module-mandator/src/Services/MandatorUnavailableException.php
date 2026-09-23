<?php

namespace Z77\Module\Mandator\Services;

/**
 * The mandator cannot be read at all — not «none saved yet» (that is a
 * plain `null` from `CurrentMandator::find()`), but one of exactly TWO
 * installation states (review 2026-09-23, owner decision: a MESSAGE, never
 * a 500, on a running bookkeeping):
 *
 *   - {@see moduleNotRegistered()} — the package is in `vendor/` but
 *     `mandator` is not a registered module (an upgraded installation whose
 *     project `composer.json` lacks the psr-4 entry), so its entity is not
 *     announced and no repository can be asked;
 *   - {@see tableMissing()} — the module is registered but `z77-db migrate`
 *     was not run, so the table does not exist.
 *
 * {@see getMessage()} is German and names what to do; the readers
 * (`LedgerService::vatAccountFor()`, debtor's `DebtorAccounts`, the
 * mandator screen) hand it on as their own refusal. Nothing else is
 * translated into this exception — any other database failure stays an
 * error.
 */
final class MandatorUnavailableException extends \RuntimeException
{
    public const MODULE_NOT_REGISTERED = 'module-not-registered';
    public const TABLE_MISSING         = 'table-missing';

    private function __construct(public readonly string $reason, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public static function moduleNotRegistered(): self
    {
        return new self(
            self::MODULE_NOT_REGISTERED,
            'Mandanten-Modul nicht registriert — in der Projekt-composer.json unter autoload.psr-4 den Eintrag '
            . '"Z77\\\\Module\\\\Mandator\\\\": ["override/z77/module/mandator/src/"] ergänzen, dann composer update, '
            . 'php vendor/bin/z77-db migrate, und den Mandanten anlegen (Finanzen → Mandant).'
        );
    }

    public static function tableMissing(?\Throwable $previous = null): self
    {
        return new self(
            self::TABLE_MISSING,
            'Mandanten-Tabelle fehlt — die Migration fahren (php vendor/bin/z77-db migrate), dann den Mandanten anlegen (Finanzen → Mandant).',
            $previous
        );
    }
}
