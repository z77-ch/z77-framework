<?php

namespace Z77\Module\Debtor\Services;

/**
 * {@see DebtorProfileService::delete()} refused: documents are written to the
 * contact (any kind, any state, drafts included — and with them any payment or
 * dunning notice). Such a debtor can only be DEACTIVATED. The message is the
 * German sentence the user sees.
 */
final class DebtorProfileInUseException extends DebtorException
{
    public function __construct(public readonly int $documents, string $name)
    {
        parent::__construct(
            '«' . $name . '» hat ' . ($documents === 1 ? 'einen Beleg' : $documents . ' Belege')
            . ' — der Debitor kann nicht mehr entfernt, nur deaktiviert werden (Stammdaten › Aufträge › Debitoren, Schalter der Zeile).'
        );
    }
}
