<?php

namespace Z77\Module\Financial\Services;

/**
 * An existing account's number was about to change. Settings and other
 * modules name accounts by number (plan §5.1, §5.4), so a renumbered
 * account would silently repoint them — a different account is a new one.
 */
final class AccountNumberChangedException extends FinancialException
{
    public function __construct(public readonly string $storedNumber, public readonly string $newNumber)
    {
        parent::__construct("Account number is immutable: '{$storedNumber}' cannot become '{$newNumber}' — a different account is a new account");
    }
}
