<?php

namespace Z77\Module\Debtor\Services;

use Z77\Persistence\Validation\EntityValidator;

/**
 * A file-based master-data row failed its validator inside the write
 * service. Carries the validator, so the screen re-renders the form with
 * the field errors instead of a bare message (the `InvalidAccountException`
 * model). NOT the row: unlike a Doctrine entity, a file row is never
 * managed, so the object the caller handed in is still the draft to
 * re-render — there is nothing to hand back.
 */
final class InvalidMasterDataException extends DebtorException
{
    public function __construct(
        public readonly EntityValidator $validator,
    ) {
        parent::__construct('Master data refused by ' . $validator::class);
    }
}
