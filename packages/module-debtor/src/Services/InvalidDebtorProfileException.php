<?php

namespace Z77\Module\Debtor\Services;

use Z77\Module\Debtor\Entities\DebtorProfile;
use Z77\Module\Debtor\Validators\DebtorProfileValidator;

/**
 * A {@see DebtorProfile} failed validation in `DebtorProfileService`.
 * Carries the validator AND the draft, so a refused update re-renders the
 * form with what the user typed — the managed entity was never touched
 * (ADR-039 decision 9).
 */
final class InvalidDebtorProfileException extends DebtorException
{
    public function __construct(
        public readonly DebtorProfileValidator $validator,
        public readonly DebtorProfile $profile,
    ) {
        parent::__construct('Debtor profile refused by its validator');
    }
}
