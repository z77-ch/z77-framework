<?php

namespace Z77\Module\Debtor\Services;

/**
 * `InvoicingService` refused a draft or a state change (the booking print
 * included). `reason` is a stable code (the `PostingRefusedException` model) a screen maps to a field
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
    public const TARGET_UNKNOWN      = 'target-unknown';
    public const TARGET_INACTIVE     = 'target-inactive';
    public const NO_PAYMENT_PART     = 'no-payment-part';
    /** The booking print: the payment part the document would print has problems (`QrBill::problems()`, owner 2026-10-13). */
    public const PAYMENT_PART        = 'payment-part';
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
    /** A draft composed under another VAT liability than the mandator's now (owner 2026-10-21): saved again first, then booked. */
    public const VAT_LIABILITY_CHANGED = 'vat-liability-changed';
    /** The optional subject («Betreff», P7 2026-10-14) is longer than `Invoice::SUBJECT_LENGTH`. */
    public const SUBJECT             = 'subject';

    /** The optional subject label («Betreffname», 2026-10-21) is longer than `SubjectLabel::LENGTH` after normalising. */
    public const SUBJECT_LABEL       = 'subject-label';
    public const NUMBER_RANGE_FULL   = 'number-range-full';
    // the lines
    public const LINE                = 'line';
    public const LINE_TAX_SHARE      = 'line-tax-share';
    public const TAX_CODE_UNKNOWN    = 'tax-code-unknown';
    public const TAX_CODE_INACTIVE   = 'tax-code-inactive';
    public const NO_TAX_RATE         = 'no-tax-rate';
    public const ACCOUNT_NOT_POSTABLE = 'account-not-postable';
    /** A line's account is not of the chart's type «Ertrag» (owner 2026-10-10). */
    public const ACCOUNT_NOT_REVENUE  = 'account-not-revenue';
    /** A line's revenue account is not flagged «auf Belegen wählbar» in the chart (owner 2026-10-12). */
    public const ACCOUNT_NOT_SELECTABLE = 'account-not-selectable';

    public function __construct(public readonly string $reason, string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
