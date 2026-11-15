<?php

namespace Z77\Module\Creditor\Recognition;

use Z77\Shared\Money\Money;
use Z77\Shared\Payment\Iban;

/**
 * What {@see SwissQrBillText} read from the payment part of a QR-bill — validated values only
 * (an IBAN with wrong check digits, a reference that fails its check, an amount out of range is
 * null here and named in {@see $warnings}). Plain data; the recognition service decides what
 * it fills in (only what is still empty, plan §3 step 5).
 *
 * {@see $confidence}: HIGH = the IBAN printed twice (receipt and payment part) and agreeing, no
 * warning; MEDIUM = no warning, but read once; LOW = any warning or no valid IBAN.
 */
final class RecognizedPayment
{
    public const CONFIDENCE_HIGH   = 'high';
    public const CONFIDENCE_MEDIUM = 'medium';
    public const CONFIDENCE_LOW    = 'low';

    public const REFERENCE_QRR  = 'QRR';
    public const REFERENCE_SCOR = 'SCOR';
    public const REFERENCE_NON  = 'NON';

    /**
     * @param list<string> $creditorAddress the lines under the name (street, postcode + town)
     * @param list<string> $debtorAddress
     * @param list<string> $warnings German, for the row
     */
    public function __construct(
        public readonly ?string $iban,                 // normalised, check digits valid
        public readonly bool $isQrIban,
        public readonly ?string $currency,             // CHF | EUR
        public readonly ?Money $amount,                // null = left blank on the slip (or unreadable — then a warning)
        public readonly ?string $referenceType,        // QRR | SCOR | NON; null = a reference printed but invalid
        public readonly ?string $reference,            // normalised (no spaces)
        public readonly ?string $creditorName,
        public readonly array $creditorAddress,
        public readonly ?string $creditorPostalCode,
        public readonly ?string $creditorTown,
        public readonly ?string $debtorName,           // null = «Zahlbar durch (Name/Adresse)» left empty
        public readonly array $debtorAddress,
        public readonly ?string $message,              // the unstructured message, without the S1 part
        public readonly ?SwicoBillingInfo $billing,
        public readonly bool $notForPayment,           // «NICHT ZUR ZAHLUNG VERWENDEN» printed
        public readonly int $occurrences,              // how many «Konto / Zahlbar an» blocks carried a valid IBAN
        public readonly array $warnings,
    ) {}

    public function confidence(): string
    {
        return match (true) {
            $this->iban === null || $this->warnings !== [] => self::CONFIDENCE_LOW,
            $this->occurrences >= 2                       => self::CONFIDENCE_HIGH,
            default                                       => self::CONFIDENCE_MEDIUM,
        };
    }

    /** Plan §4: «usable» = a valid IBAN and either an amount or a known creditor. */
    public function isUsable(bool $creditorKnown = false): bool
    {
        return $this->iban !== null && !$this->notForPayment && ($this->amount !== null || $creditorKnown);
    }

    public function formattedIban(): ?string
    {
        return $this->iban === null ? null : Iban::format($this->iban);
    }

    /** @param list<string> $warnings */
    public function withWarnings(array $warnings): self
    {
        $copy = get_object_vars($this);
        $copy['warnings'] = array_values(array_unique(array_merge($this->warnings, $warnings)));

        return new self(...$copy);
    }
}
