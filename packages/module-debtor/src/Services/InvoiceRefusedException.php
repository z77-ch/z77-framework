<?php

namespace Z77\Module\Debtor\Services;

/**
 * `InvoicingService` refused a draft or a state change. `reason` is a
 * stable code (the `PostingRefusedException` model) a screen maps to a field
 * or a sentence; `getMessage()` is German and says what to fix — the
 * document screens of part 3 print it as it is.
 */
final class InvoiceRefusedException extends DebtorException
{
    // the party and its master data
    public const CONTACT_UNKNOWN     = 'contact-unknown';
    public const CONTACT_INACTIVE    = 'contact-inactive';
    public const NO_DEBTOR_PROFILE   = 'no-debtor-profile';
    public const DEBTOR_INACTIVE     = 'debtor-inactive';
    public const TERMS_UNKNOWN       = 'terms-unknown';
    public const TERMS_INACTIVE      = 'terms-inactive';
    public const NO_ADDRESS          = 'no-address';
    public const ADDRESS_INCOMPLETE  = 'address-incomplete';
    // the document
    public const CURRENCY            = 'currency';
    public const DATES               = 'dates';
    public const NO_LINES            = 'no-lines';
    public const NEGATIVE_TOTAL      = 'negative-total';
    public const CREDIT_NOTE_TARGET  = 'credit-note-target';
    public const CREDIT_NOTE_RATE    = 'credit-note-rate';
    public const NOT_INVOICING       = 'not-invoicing';
    public const NOT_FOUND           = 'not-found';
    public const KIND_CHANGED        = 'kind-changed';
    // the lines
    public const LINE                = 'line';
    public const LINE_TAX_SHARE      = 'line-tax-share';
    public const TAX_CODE_UNKNOWN    = 'tax-code-unknown';
    public const TAX_CODE_INACTIVE   = 'tax-code-inactive';
    public const NO_TAX_RATE         = 'no-tax-rate';
    public const ACCOUNT_NOT_POSTABLE = 'account-not-postable';

    public function __construct(public readonly string $reason, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
