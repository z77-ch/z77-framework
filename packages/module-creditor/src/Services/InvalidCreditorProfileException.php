<?php

namespace Z77\Module\Creditor\Services;

use Z77\Module\Creditor\Entities\CreditorProfile;
use Z77\Module\Creditor\Validators\CreditorProfileValidator;

/**
 * A {@see CreditorProfile} failed validation in `CreditorProfileService`. Carries the validator, the
 * DRAFT (a detached clone with what was typed) and the IBAN rows as typed, so a refused save
 * re-renders the window with the input — the managed entity was never touched (ADR-039 decision 9).
 */
final class InvalidCreditorProfileException extends CreditorException
{
    /** @param list<array{iban: string, hint: string, active: bool}> $ibanRows */
    public function __construct(
        public readonly CreditorProfileValidator $validator,
        public readonly CreditorProfile $profile,
        public readonly array $ibanRows = [],
    ) {
        parent::__construct('Creditor profile refused by its validator');
    }

    /** Every message in one line — for a caller without a form (the contact editor's role switch). */
    public function sentence(): string
    {
        return implode(' ', [...$this->validator->getErrors(), ...array_values($this->validator->getFieldErrors())]);
    }
}
