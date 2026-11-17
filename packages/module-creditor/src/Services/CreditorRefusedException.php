<?php

namespace Z77\Module\Creditor\Services;

/**
 * A creditor write was refused as a whole — the message is German and names the next step;
 * `reason` is the stable code a caller branches on. Nothing was written.
 */
final class CreditorRefusedException extends CreditorException
{
    /** The creditor is gone, or carries another version than the caller saw. */
    public const CHANGED = 'changed';

    /** The creditor still has invoices — it can only be deactivated, never removed. */
    public const IN_USE = 'in-use';

    /** The intake got no bytes. */
    public const EMPTY_FILE = 'empty-file';

    /** The file is larger than `creditorConfig['intake']['maxBytes']`. */
    public const TOO_LARGE = 'too-large';

    /** The bytes are no PDF (`%PDF-` header and the sniffed type). */
    public const NOT_PDF = 'not-pdf';

    /** The identical file (same SHA-256) was read before — nothing stored (owner answer 4). */
    public const DUPLICATE_FILE = 'duplicate-file';

    /** «Journal verbuchen»: no paid invoice up to the cut-off — nothing posted. */
    public const EMPTY_RUN = 'empty-run';

    /** «Journal verbuchen»: an invoice of the run is incomplete or blocked as a suspected duplicate — nothing posted. */
    public const INCOMPLETE = 'incomplete';

    /** The posting lines cannot be built (an account, a tax code, the VAT liability) — nothing posted. */
    public const NOT_POSTABLE = 'not-postable';

    /** The ledger refused the posting (no fiscal year, a closed period …) — nothing posted. */
    public const LEDGER = 'ledger';

    /**
     * A bank movement cannot be taken for the invoice, or its link cannot be released (ADR-061
     * stage C1): the movement is gone or already linked, the amounts differ, the invoice is
     * blocked or — for a release — already posted.
     */
    public const BANK = 'bank';

    public function __construct(public readonly string $reason, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
