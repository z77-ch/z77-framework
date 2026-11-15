<?php

namespace Z77\Module\Creditor\Services;

/**
 * A creditor invoice save was refused by its field checks — `errors` maps the field (`amount`,
 * `iban`, `line_0_account` …) to ONE German message, so the window re-renders with the input and
 * the messages. Nothing was written (ADR-039 decision 9).
 */
final class InvalidCreditorInvoiceException extends CreditorException
{
    /** @param array<string, string> $errors field → German message */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Creditor invoice refused: ' . implode(' ', $errors));
    }
}
